<?php

declare(strict_types=1);

namespace Componenta\DI\Tests;

use Attribute;
use Componenta\Caster\NullCasterProvider;
use Componenta\Config\Config;
use Componenta\DI\AliasResolver;
use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\CallableExecutor;
use Componenta\DI\CallableInvoker;
use Componenta\DI\CallableResolver;
use Componenta\DI\Compile\Factory\CompiledFactoryDefinition;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\FactoryInterface;
use Componenta\DI\NullContainer;
use Componenta\DI\Resolver\Entry\InvokableResolver;
use Componenta\DI\Resolver\Parameter\ArrayResolver;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Resolver\Parameter\Request\ExtractorInterface;
use Componenta\DI\Resolver\Parameter\Request\RequestParameter;
use Componenta\DI\Resolver\Parameter\Request\RequestResolver;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Fixture\FakeServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionFunction;

final class AuditOptionalInvokable
{
    public function __construct(public string $value = 'default') {}
}

#[Lazy]
final class AuditLazyOptionalInvokable
{
    public function __construct(public string $value = 'default') {}
}

#[Proxy]
final class AuditProxyOptionalInvokable
{
    public function __construct(public string $value = 'default') {}
}

final class AuditClassDefinitionTarget
{
    public function __construct(public string $value) {}
}

final class AuditAotZeroArgumentEntry {}

final class AuditNeverResolver implements ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool
    {
        return false;
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        return null;
    }
}

final class AuditConfiguredBuilder extends ContainerBuilder
{
    public readonly AuditNeverResolver $defaultResolver;

    public function __construct()
    {
        $this->defaultResolver = new AuditNeverResolver();
        $this->addService('audit.default.service', 'constructor-default');
        $this->addService('audit.override.service', 'constructor-default');
        $this->addDelegator('audit.decorated', static fn(mixed $value): mixed => $value);
        $this->addParameterResolver($this->defaultResolver, -1000);
        $this->replaceParameterResolvers();
        $this->replaceAttributeHandlers();
    }
}

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::IS_REPEATABLE)]
final readonly class AuditRepeatableExtractor implements ExtractorInterface
{
    public function __construct(private string $value) {}

    public function extract(ServerRequestInterface $request): mixed
    {
        return $this->value;
    }
}

describe('audit consistency regressions', function () {
    it('does not forward make context through explicit invokable entries', function () {
        $container = (new ContainerBuilder())
            ->addInvokable(AuditOptionalInvokable::class)
            ->build();

        expect($container->get(AuditOptionalInvokable::class)->value)->toBe('default')
            ->and($container->make(
                AuditOptionalInvokable::class,
                ['value' => 'runtime'],
            )->value)->toBe('default');
    });

    it('ignores invokable context and creation-strategy attributes', function () {
        $resolver = new InvokableResolver([
            AuditOptionalInvokable::class,
            AuditLazyOptionalInvokable::class,
            AuditProxyOptionalInvokable::class,
        ]);

        expect($resolver->resolve(
            AuditOptionalInvokable::class,
            ['value' => 'eager'],
        )->value)->toBe('default')
            ->and($resolver->resolve(
                AuditLazyOptionalInvokable::class,
                ['value' => 'lazy'],
            )->value)->toBe('default')
            ->and($resolver->resolve(
                AuditProxyOptionalInvokable::class,
                ['value' => 'proxy'],
            )->value)->toBe('default');
    });

    it('forwards make context through ClassDefinition factories and lets it override configured params', function () {
        $container = (new ContainerBuilder())->build();
        $container->set(
            'audit.class-definition',
            ClassDefinition::create(AuditClassDefinitionTarget::class)
                ->constructor(['value' => 'configured']),
        );

        expect($container->make(
            'audit.class-definition',
            ['value' => 'runtime'],
        )->value)->toBe('runtime');

        $container->set(
            'audit.class-definition-unconfigured',
            ClassDefinition::create(AuditClassDefinitionTarget::class),
        );

        expect($container->make(
            'audit.class-definition-unconfigured',
            ['value' => 'runtime-only'],
        )->value)->toBe('runtime-only');
    });

    it('returns zero-argument AOT roots as compiled factories', function () {
        $directory = sys_get_temp_dir()
            . '/componenta-audit-aot-'
            . bin2hex(random_bytes(5));

        try {
            $builder = new ContainerBuilder();
            $compiled = $builder->compileFactories(
                [AuditAotZeroArgumentEntry::class],
                $directory,
            );

            expect($compiled)->toHaveKey(AuditAotZeroArgumentEntry::class)
                ->and($compiled[AuditAotZeroArgumentEntry::class])
                ->toBeInstanceOf(CompiledFactoryDefinition::class);

            $encoded = [];
            foreach ($compiled as $id => $definition) {
                $encoded[$id] = $definition->encode();
            }

            $dependencies = ContainerBuilder::normalizeDependencies([
                ConfigKey::FACTORIES => $encoded,
            ]);
            $production = ContainerBuilder::configureFromCache(
                new Config([]),
                [
                    'version' => ContainerBuilder::CACHE_VERSION,
                    ConfigKey::DEPENDENCIES => $dependencies,
                ],
                $directory,
            )->build();

            expect($production->get(AuditAotZeroArgumentEntry::class))
                ->toBeInstanceOf(AuditAotZeroArgumentEntry::class);
        } finally {
            foreach (glob($directory . '/container.factories.*.php') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($directory);
        }
    });

    it('uses the same non-empty id boundary for runtime mutations and aliases', function () {
        $container = (new ContainerBuilder())->build();

        expect(fn() => $container->set('', new \stdClass()))
            ->toThrow(InvalidConfigurationException::class)
            ->and(fn() => $container->delegator('', static fn(mixed $value): mixed => $value))
            ->toThrow(InvalidConfigurationException::class)
            ->and(fn() => $container->alias('', 'audit.target'))
            ->toThrow(InvalidConfigurationException::class)
            ->and(fn() => $container->alias('audit.alias', ''))
            ->toThrow(InvalidConfigurationException::class)
            ->and(fn() => new AliasResolver(['' => 'audit.target']))
            ->toThrow(InvalidConfigurationException::class)
            ->and(fn() => new AliasResolver(['audit.alias' => '']))
            ->toThrow(InvalidConfigurationException::class);
    });

    it('propagates callable invocation engine failures through both invokers', function () {
        $callable = static fn(): string => 'ok';
        $params = ['unexpected' => true];
        $executor = new CallableExecutor(
            new CallableResolver(new NullContainer()),
            new ParametersResolver(new ArrayResolver()),
        );

        expect(fn() => (new CallableInvoker())->call($callable, $params))
            ->toThrow(\Error::class)
            ->and(fn() => $executor->call($callable, $params))
            ->toThrow(\Error::class);
    });

    it('preserves subclass builder defaults while declarative configuration overrides matching values', function () {
        $replacementResolver = new AuditNeverResolver();
        $builder = AuditConfiguredBuilder::configureWithDependencies(
            new Config([]),
            [
                ConfigKey::SERVICES => [
                    'audit.override.service' => 'configuration',
                ],
                ConfigKey::DELEGATORS => [
                    'audit.decorated' => [static fn(mixed $value): mixed => $value],
                ],
                ConfigKey::PARAMETER_RESOLVERS => [
                    -1000 => $replacementResolver,
                ],
            ],
        );
        $dependencies = $builder->toArray()[ConfigKey::DEPENDENCIES];

        expect($dependencies[ConfigKey::SERVICES]['audit.default.service'])
            ->toBe('constructor-default')
            ->and($dependencies[ConfigKey::SERVICES]['audit.override.service'])
            ->toBe('configuration')
            ->and($dependencies[ConfigKey::PARAMETER_RESOLVERS_REPLACE])->toBeTrue()
            ->and($dependencies[ConfigKey::ATTRIBUTE_HANDLERS_REPLACE])->toBeTrue()
            ->and($dependencies[ConfigKey::DELEGATORS]['audit.decorated'])->toHaveCount(2)
            ->and($dependencies[ConfigKey::PARAMETER_RESOLVERS])->toBe([
                -1000 => $replacementResolver,
            ]);

        $overriddenFlags = AuditConfiguredBuilder::configureWithDependencies(
            new Config([]),
            [
                ConfigKey::PARAMETER_RESOLVERS_REPLACE => false,
                ConfigKey::ATTRIBUTE_HANDLERS_REPLACE => false,
            ],
        )->toArray()[ConfigKey::DEPENDENCIES];

        expect($overriddenFlags[ConfigKey::PARAMETER_RESOLVERS_REPLACE])->toBeFalse()
            ->and($overriddenFlags[ConfigKey::ATTRIBUTE_HANDLERS_REPLACE])->toBeFalse();
    });

    it('rejects repeated request extractor instances of the same class as ambiguous', function () {
        $callable = static function (
            #[AuditRepeatableExtractor('first'), AuditRepeatableExtractor('second')]
            string $value,
        ): void {};
        $parameter = (new ReflectionFunction($callable))->getParameters()[0];
        $factory = new class () implements FactoryInterface {
            public function make(string $entry, array $params = []): object
            {
                throw new \LogicException('DTO factory must not run for scalar extraction.');
            }
        };
        $resolver = new RequestResolver($factory, new NullCasterProvider());
        $context = new ParameterResolutionContext(RequestParameter::with(
            [],
            new FakeServerRequest('GET', '/'),
        ));

        expect(fn() => $resolver->resolveParameter(
            new ParameterTarget($parameter),
            $context,
        ))->toThrow(
            ResolutionException::class,
            'multiple request extraction attributes are ambiguous',
        );
    });
});

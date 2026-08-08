<?php

declare(strict_types=1);

namespace Componenta\DI\Tests;

use Componenta\Config\Config;
use Componenta\DI\Attribute\Inject;
use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Compile\Entry\GeneratedEntryResolverLoader;
use Componenta\DI\ConfigKey;
use Componenta\DI\Container;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Definition\Definition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\ProxyFactoryInterface;
use Componenta\DI\Resolver\Attribute\AttributeProcessor;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Psr\Container\ContainerInterface;

final class BuilderGeneratedEntry
{
    public function __construct(
        public int $value = 1,
    ) {}
}

final class BuilderInjectedDependency {}

final class BuilderAttributedResolver implements
    \Componenta\DI\Resolver\Parameter\ParameterResolverInterface
{
    #[Inject]
    public BuilderInjectedDependency $dependency;

    public function supports(
        \Componenta\DI\Resolver\Target\ParameterTarget $target,
    ): bool {
        return false;
    }

    public function resolveParameter(
        \Componenta\DI\Resolver\Target\ParameterTarget $target,
        \Componenta\DI\Resolver\Parameter\ParameterResolutionContext $context,
    ): ?array {
        return null;
    }
}

#[Lazy]
final class BuilderLazyEntry
{
    public function __construct(public int $value = 5) {}
}

final class BuilderProxyFactory implements ProxyFactoryInterface
{
    public int $lazyCalls = 0;
    public int $proxyCalls = 0;

    public function makeLazy(string $class, callable $initializer): object
    {
        ++$this->lazyCalls;
        $entry = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        $initializer($entry);

        return $entry;
    }

    public function makeProxy(string $class, callable $initializer): object
    {
        ++$this->proxyCalls;

        return $initializer((new \ReflectionClass($class))->newInstanceWithoutConstructor());
    }
}

final class BuilderExternalContainer implements ContainerInterface
{
    /** @param array<string, mixed> $entries */
    public function __construct(private array $entries) {}

    public function get(string $id): mixed
    {
        return $this->entries[$id] ?? throw new \RuntimeException($id);
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->entries);
    }
}

final class BuilderMagicDelegator
{
    public function __call(string $name, array $arguments): mixed
    {
        return $arguments[0] ?? null;
    }
}

final class BuilderWithProxyFactory extends ContainerBuilder
{
    public readonly BuilderProxyFactory $proxy;

    public function __construct()
    {
        $this->proxy = new BuilderProxyFactory();
    }

    protected function createProxyFactory(): ProxyFactoryInterface
    {
        return $this->proxy;
    }
}

describe('ContainerBuilder', function () {
    it('builds one runtime container and resolves fresh objects with explicit context', function () {
        $container = (new ContainerBuilder())->build();

        $first = $container->make(BuilderGeneratedEntry::class, ['value' => 9]);
        $second = $container->make(BuilderGeneratedEntry::class, ['value' => 10]);

        expect($first)->toBeInstanceOf(BuilderGeneratedEntry::class)
            ->and($first->value)->toBe(9)
            ->and($second->value)->toBe(10)
            ->and($second)->not->toBe($first);
    });

    it('installs default attribute handlers before materializing custom parameter resolvers', function () {
        $dependency = new BuilderInjectedDependency();
        $container = (new ContainerBuilder())
            ->addService(BuilderInjectedDependency::class, $dependency)
            ->addParameterResolver(BuilderAttributedResolver::class, -1000)
            ->build();

        $custom = array_find(
            $container->get(ParametersResolver::class)->resolverList,
            static fn(object $resolver): bool => $resolver instanceof BuilderAttributedResolver,
        );

        expect($custom)->toBeInstanceOf(BuilderAttributedResolver::class)
            ->and($custom->dependency)->toBe($dependency);
    });

    it('uses one proxy collaborator behind reflection and the public container facade', function () {
        $builder = new BuilderWithProxyFactory();
        $container = $builder->build();

        $entry = $container->make(BuilderLazyEntry::class);
        $container->makeLazy(
            BuilderGeneratedEntry::class,
            static function (object $entry): void {
                $entry->__construct(7);
            },
        );

        expect($entry)->toBeInstanceOf(BuilderLazyEntry::class)
            ->and($entry->value)->toBe(5)
            ->and($builder->proxy->lazyCalls)->toBe(2)
            ->and($container->get(ProxyFactoryInterface::class))->toBe($container);
    });

    it('compiles and installs a generated entry resolver without replacing the container', function () {
        $file = sys_get_temp_dir()
            . '/componenta-di-builder-'
            . bin2hex(random_bytes(6))
            . '.php';

        try {
            (new ContainerBuilder())->compileGeneratedEntryResolver(
                [BuilderGeneratedEntry::class],
                $file,
                namespace: 'Componenta\\DI\\Tests\\GeneratedBuilder',
                releaseFingerprint: 'builder-test-release',
            );

            $container = (new ContainerBuilder())
                ->useGeneratedEntryResolver($file, 'builder-test-release')
                ->build();

            $entry = $container->make(
                BuilderGeneratedEntry::class,
                ['value' => 33],
            );

            expect($entry)->toBeInstanceOf(BuilderGeneratedEntry::class)
                ->and($entry->value)->toBe(33);
        } finally {
            @unlink($file);
        }
    });

    it('falls back to reflection when the configured generated file is invalid', function () {
        $file = sys_get_temp_dir()
            . '/componenta-di-invalid-'
            . bin2hex(random_bytes(6))
            . '.php';
        file_put_contents($file, "<?php\nreturn null;\n");

        try {
            $container = (new ContainerBuilder())
                ->useGeneratedEntryResolver($file)
                ->build();

            expect($container->make(BuilderGeneratedEntry::class)->value)->toBe(1);
        } finally {
            @unlink($file);
        }
    });

    it('installs core pipeline services atomically and forbids rebinding or decoration', function () {
        $container = (new ContainerBuilder())->build();

        expect($container->get(ParametersResolver::class))
            ->toBeInstanceOf(ParametersResolver::class)
            ->and($container->get(AttributeProcessor::class))
            ->toBeInstanceOf(AttributeProcessor::class)
            ->and(fn() => $container->set(ParametersResolver::class, new ParametersResolver()))
            ->toThrow(InvalidConfigurationException::class)
            ->and(fn() => $container->alias(ParametersResolver::class, BuilderGeneratedEntry::class))
            ->toThrow(InvalidConfigurationException::class)
            ->and(fn() => $container->delegator(ParametersResolver::class, static fn($entry) => $entry))
            ->toThrow(InvalidConfigurationException::class)
            ->and(fn() => $container->get(ParametersResolver::class)->add(
                new \Componenta\DI\Resolver\Parameter\ArrayResolver(),
            ))->toThrow(\LogicException::class)
            ->and(fn() => $container->get(
                \Componenta\DI\Resolver\Attribute\AttributeHandlerRegistry::class,
            )->add(new \Componenta\DI\Resolver\Attribute\Handler\NoConstructorHandler()))
            ->toThrow(\LogicException::class);
    });

    it('canonicalizes definitions registered through aliases', function () {
        $container = (new ContainerBuilder())
            ->addAlias('builder.entry', BuilderGeneratedEntry::class)
            ->build();

        $container->set(
            'builder.entry',
            Definition::autowire(BuilderGeneratedEntry::class)
                ->constructor(['value' => 44]),
        );

        expect($container->get('builder.entry'))
            ->toBeInstanceOf(BuilderGeneratedEntry::class)
            ->and($container->get('builder.entry')->value)->toBe(44);
    });

    it('rejects legacy, unknown and malformed dependency configuration', function () {
        expect(fn() => ContainerBuilder::configureWithDependencies(
            new Config([]),
            ['property_resolvers' => []],
        ))->toThrow(InvalidConfigurationException::class)
            ->and(fn() => ContainerBuilder::configureWithDependencies(
                new Config([]),
                [ConfigKey::PARAMETER_RESOLVERS => ['priority' => BuilderGeneratedEntry::class]],
            ))->toThrow(InvalidConfigurationException::class)
            ->and(fn() => ContainerBuilder::configureWithDependencies(
                new Config([]),
                [ConfigKey::ATTRIBUTE_HANDLERS => ['named' => BuilderGeneratedEntry::class]],
            ))->toThrow(InvalidConfigurationException::class)
            ->and(fn() => ContainerBuilder::configureWithDependencies(
                new Config([]),
                [ConfigKey::PARAMETER_RESOLVERS => [100 => new \stdClass()]],
            ))->toThrow(InvalidConfigurationException::class)
            ->and(fn() => (new ContainerBuilder())->addAttributeHandler(new \stdClass()))
            ->toThrow(InvalidConfigurationException::class)
            ->and(fn() => (new ContainerBuilder())->addParameterResolver(new \stdClass()))
            ->toThrow(InvalidConfigurationException::class);
    });

    it('rejects unreachable factories and canonical bindings to protected services', function () {
        expect(fn() => (new ContainerBuilder())
            ->addFactory('builder.alias', static fn() => new BuilderGeneratedEntry())
            ->addAlias('builder.alias', BuilderGeneratedEntry::class)
            ->build())
            ->toThrow(InvalidConfigurationException::class)
            ->and(fn() => (new ContainerBuilder())
                ->addAlias('builder.parameters', ParametersResolver::class)
                ->addService('builder.parameters', new \stdClass())
                ->build())
            ->toThrow(InvalidConfigurationException::class);
    });

    it('rejects multiple binding mechanisms for the same canonical id', function () {
        expect(fn() => (new ContainerBuilder())
            ->addFactory(BuilderGeneratedEntry::class, static fn() => new BuilderGeneratedEntry())
            ->addService(BuilderGeneratedEntry::class, new BuilderGeneratedEntry())
            ->build())
            ->toThrow(InvalidConfigurationException::class)
            ->and(fn() => (new ContainerBuilder())
                ->addFactory(BuilderGeneratedEntry::class, static fn() => new BuilderGeneratedEntry())
                ->addInvokable(BuilderGeneratedEntry::class)
                ->build())
            ->toThrow(InvalidConfigurationException::class)
            ->and(fn() => (new ContainerBuilder())
                ->addAlias('builder.service.alias', BuilderGeneratedEntry::class)
                ->addServices([
                    'builder.service.alias' => new BuilderGeneratedEntry(1),
                    BuilderGeneratedEntry::class => new BuilderGeneratedEntry(2),
                ])
                ->build())
            ->toThrow(InvalidConfigurationException::class);
    });

    it('normalizes duplicate invokable classes from configuration', function () {
        $builder = ContainerBuilder::configureWithDependencies(
            new Config([]),
            [ConfigKey::INVOKABLES => [BuilderGeneratedEntry::class, BuilderGeneratedEntry::class]],
        );

        expect($builder->toArray()[ConfigKey::DEPENDENCIES][ConfigKey::INVOKABLES])
            ->toBe([BuilderGeneratedEntry::class]);
    });

    it('omits empty and default dependency sections from normalized cache data', function () {
        $normalized = ContainerBuilder::normalizeDependencies([]);

        expect(array_keys($normalized))->toBe([ConfigKey::ALIASES]);

        $configured = ContainerBuilder::normalizeDependencies([
            ConfigKey::PARAMETER_RESOLVERS_REPLACE => true,
            ConfigKey::ATTRIBUTE_HANDLERS_REPLACE => true,
            ConfigKey::GENERATED_ENTRY_RESOLVER_FILE => 'container.resolver.php',
            ConfigKey::GENERATED_ENTRY_RESOLVER_RELEASE_FINGERPRINT => 'release',
        ]);

        expect($configured)
            ->toHaveKey(ConfigKey::PARAMETER_RESOLVERS_REPLACE, true)
            ->toHaveKey(ConfigKey::ATTRIBUTE_HANDLERS_REPLACE, true)
            ->toHaveKey(ConfigKey::GENERATED_ENTRY_RESOLVER_FILE, 'container.resolver.php')
            ->toHaveKey(ConfigKey::GENERATED_ENTRY_RESOLVER_RELEASE_FINGERPRINT, 'release');
    });

    it('uses singular validation for every bulk registration API', function () {
        expect(fn() => (new ContainerBuilder())->addFactories([0 => static fn() => null]))
            ->toThrow(InvalidConfigurationException::class)
            ->and(fn() => (new ContainerBuilder())->addFactories(['entry' => new \stdClass()]))
            ->toThrow(InvalidConfigurationException::class)
            ->and(fn() => (new ContainerBuilder())->addAliases(['entry' => 123]))
            ->toThrow(InvalidConfigurationException::class)
            ->and(fn() => (new ContainerBuilder())->addInvokables([new \stdClass()]))
            ->toThrow(InvalidConfigurationException::class)
            ->and(fn() => (new ContainerBuilder())->addDelegator('entry', [new \stdClass()]))
            ->toThrow(InvalidConfigurationException::class)
            ->and(fn() => (new ContainerBuilder())->addDelegators(['entry' => 123]))
            ->toThrow(InvalidConfigurationException::class)
            ->and((new ContainerBuilder())->addDelegators([
                'entry' => [new BuilderMagicDelegator(), 'dynamic'],
            ])->toArray()[ConfigKey::DEPENDENCIES][ConfigKey::DELEGATORS]['entry'])
            ->toHaveCount(1)
            ->and(fn() => (new ContainerBuilder())->addServices([0 => new \stdClass()]))
            ->toThrow(InvalidConfigurationException::class);
    });

    it('breaks mutual external-container has cycles without hiding get failures', function () {
        $left = (new ContainerBuilder())->build();
        $right = (new ContainerBuilder())->build();
        $left->addContainer($right);
        $right->addContainer($left);

        expect($left->has('builder.missing'))->toBeFalse()
            ->and($right->has('builder.missing'))->toBeFalse()
            ->and(fn() => $left->get('builder.missing'))
            ->toThrow(NotFoundException::class);
    });

    it('keeps local base entries ahead of external containers deterministically', function () {
        $container = (new ContainerBuilder())
            ->addService('builder.shared', 'local')
            ->build();
        $container->addContainer(new BuilderExternalContainer([
            'builder.shared' => 'external',
            'builder.external-only' => 'external-only',
        ]));

        expect($container->get('builder.shared'))->toBe('local')
            ->and($container->get('builder.external-only'))->toBe('external-only')
            ->and(fn() => $container->addContainer($container))
            ->toThrow(InvalidConfigurationException::class);
    });

    it('does not expose removed legacy API', function () {
        expect(method_exists(ContainerBuilder::class, 'addAutowire'))->toBeFalse()
            ->and(method_exists(ContainerBuilder::class, 'compilePlans'))->toBeFalse()
            ->and(defined(ConfigKey::class . '::PROPERTY_RESOLVERS'))->toBeFalse()
            ->and(defined(ConfigKey::class . '::AUTOWIRES'))->toBeFalse()
            ->and(class_exists(GeneratedEntryResolverLoader::class))->toBeTrue()
            ->and(class_exists('Componenta\\DI\\Compile\\PlanCompiler'))->toBeFalse()
            ->and(interface_exists('Componenta\\DI\\Resolver\\Entry\\InstantiatorInterface'))->toBeFalse()
            ->and(class_exists('Componenta\\DI\\Resolver\\Target\\PropertyTarget'))->toBeFalse()
            ->and(class_exists('Componenta\\DI\\Resolver\\FactoryConfigReader'))->toBeFalse()
            ->and(class_exists('Componenta\\DI\\Resolver\\Entry\\ContainerCallableInvoker'))->toBeFalse()
            ->and(method_exists('Componenta\\DI\\CycleGuard', 'track'))->toBeFalse()
            ->and(method_exists('Componenta\\DI\\DelegatorRegistry', 'has'))->toBeFalse()
            ->and(method_exists('Componenta\\DI\\ExternalContainerRegistry', 'getIterator'))->toBeFalse();
    });
});

<?php

declare(strict_types=1);

namespace Componenta\DI\Tests;

use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Componenta\DI\Attribute\Inject;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Psr\Container\ContainerInterface;

final class BuilderGeneratedEntry
{
    public function __construct(
        public int $value = 1,
    ) {}
}

final class BuilderInjectedDependency {}

final class BuilderAttributedResolver implements ParameterResolverInterface
{
    #[Inject]
    public BuilderInjectedDependency $dependency;

    public function supports(ParameterTarget $target): bool
    {
        return $target->name === 'custom';
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        return [$target->position, $this->dependency];
    }
}

final readonly class BuilderCustomResolverTarget
{
    public function __construct(public mixed $custom) {}
}

final readonly class BuilderFactoryResolverTarget
{
    public function __construct(public mixed $fromFactory) {}
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

describe('ContainerBuilder', function () {
    it('shares the built container identity with bootstrap values and factories', function () {
        $factoryContainer = null;
        $container = (new ContainerBuilder())
            ->addFactory('builder.identity', static function (ContainerValue $value) use (&$factoryContainer): object {
                $factoryContainer = $value->value;

                return new \stdClass();
            })
            ->build();

        $container->get('builder.identity');

        expect($factoryContainer)->toBe($container)
            ->and($container->get(ContainerValue::class)->value)->toBe($container);
    });

    it('uses a parameter resolver supplied by a callable factory', function () {
        $resolver = new class () implements ParameterResolverInterface {
            public function supports(ParameterTarget $target): bool
            {
                return $target->name === 'fromFactory';
            }

            public function resolveParameter(
                ParameterTarget $target,
                ParameterResolutionContext $context,
            ): ?array {
                return [$target->position, 'resolved-by-factory'];
            }
        };
        $container = (new ContainerBuilder())
            ->addParameterResolver(static fn(): object => $resolver, 5000)
            ->build();

        expect($container->make(BuilderFactoryResolverTarget::class)->fromFactory)
            ->toBe('resolved-by-factory');
    });

    it('builds one runtime container and resolves fresh objects with explicit context', function () {
        $container = (new ContainerBuilder())->build();

        $first = $container->make(BuilderGeneratedEntry::class, ['value' => 9]);
        $second = $container->make(BuilderGeneratedEntry::class, ['value' => 10]);

        expect($first)->toBeInstanceOf(BuilderGeneratedEntry::class)
            ->and($first->value)->toBe(9)
            ->and($second->value)->toBe(10)
            ->and($second)->not->toBe($first);
    });

    it('applies default attribute injection when materializing a custom resolver', function () {
        $dependency = new BuilderInjectedDependency();
        $container = (new ContainerBuilder())
            ->addService(BuilderInjectedDependency::class, $dependency)
            ->addParameterResolver(BuilderAttributedResolver::class, 5000)
            ->build();

        expect($container->make(BuilderCustomResolverTarget::class)->custom)
            ->toBe($dependency);
    });

    it('canonicalizes definitions registered through aliases', function () {
        $container = (new ContainerBuilder())
            ->addAlias('builder.entry', BuilderGeneratedEntry::class)
            ->build();

        $container->set(
            'builder.entry',
            ClassDefinition::create(BuilderGeneratedEntry::class)
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

    it('rejects a factory shadowed by an alias for the same id', function () {
        expect(fn() => (new ContainerBuilder())
            ->addFactory('builder.alias', static fn() => new BuilderGeneratedEntry())
            ->addAlias('builder.alias', BuilderGeneratedEntry::class)
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
        ]);

        expect($configured)
            ->toHaveKey(ConfigKey::PARAMETER_RESOLVERS_REPLACE, true)
            ->toHaveKey(ConfigKey::ATTRIBUTE_HANDLERS_REPLACE, true);
    });

    it('revalidates persistent cache after a conflicting runtime binding is added', function () {
        $builder = ContainerBuilder::configureFromCache(
            new Config([]),
            [
                'version' => ContainerBuilder::CACHE_VERSION,
                ConfigKey::DEPENDENCIES => [
                    ConfigKey::FACTORIES => [
                        'builder.conflict' => static fn() => new \stdClass(),
                    ],
                ],
            ],
        );
        $builder->addService('builder.conflict', new \stdClass());

        expect(fn() => $builder->build())
            ->toThrow(InvalidConfigurationException::class);
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

    it('keeps external containers ahead of cached local entries deterministically', function () {
        $container = (new ContainerBuilder())
            ->addService('builder.shared', 'local')
            ->build();

        expect($container->get('builder.shared'))->toBe('local');

        $container->addContainer(new BuilderExternalContainer([
            'builder.shared' => 'external',
            'builder.external-only' => 'external-only',
        ]));

        expect($container->get('builder.shared'))->toBe('external')
            ->and($container->get('builder.external-only'))->toBe('external-only')
            ->and(fn() => $container->addContainer($container))
            ->toThrow(InvalidConfigurationException::class);
    });
});

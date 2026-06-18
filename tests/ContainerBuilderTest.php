<?php

declare(strict_types=1);

require_once __DIR__ . '/Fixture/container_helpers.php';

use Componenta\DI\Compile\PlanCompiler;
use Componenta\Config\Config;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\ConfigKey;
use Componenta\DI\Container;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Definition\Definition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Tests\Fixture\CacheConsumer;
use Componenta\DI\Tests\Fixture\CacheDelegator;
use Componenta\DI\Tests\Fixture\CacheFactory;
use Componenta\DI\Tests\Fixture\ServiceWithParam;
use Componenta\DI\Tests\Fixture\SimpleService;
use Psr\Container\ContainerInterface;

final class ContainerBuilderPlanModeTarget
{
    public function __construct(
        string $slug,
        SimpleService $service,
    ) {}
}

final class ContainerBuilderEntryIdTarget
{
    public function __construct(
        #[EntryId(ContainerInterface::class)]
        public ContainerInterface $container,
    ) {}
}

describe('ContainerBuilder', function () {
    it('build() returns a Container instance', function () {
        expect(minimalBuilder()->build())->toBeInstanceOf(Container::class);
    });

    it('rejects a non-array dependencies section', function () {
        $config = new Config([
            ConfigKey::DEPENDENCIES => 'invalid',
        ]);

        expect(fn () => ContainerBuilder::configure($config))
            ->toThrow(InvalidConfigurationException::class, 'dependencies section must be an array');
    });

    describe('addService', function () {
        it('exposes the value under the registered id', function () {
            $obj = new stdClass();

            $container = minimalBuilder()
                ->addService('svc', $obj)
                ->build();

            expect($container->get('svc'))->toBe($obj);
        });

        it('addServices registers multiple in one call', function () {
            $container = minimalBuilder()
                ->addServices(['a' => 1, 'b' => 2])
                ->build();

            expect($container->get('a'))->toBe(1)
                ->and($container->get('b'))->toBe(2);
        });
    });

    describe('addFactory / addFactories', function () {
        it('invokes the factory with a container that can resolve other services', function () {
            $container = minimalBuilder()
                ->addService('dep', 'from-dep')
                ->addFactory('svc', fn (ContainerInterface $c) => 'got:' . $c->get('dep'))
                ->build();

            expect($container->get('svc'))->toBe('got:from-dep');
        });

        it('addFactories registers a batch', function () {
            $container = minimalBuilder()
                ->addFactories([
                    'a' => fn () => 'A',
                    'b' => fn () => 'B',
                ])
                ->build();

            expect($container->get('a'))->toBe('A')
                ->and($container->get('b'))->toBe('B');
        });
    });

    describe('addAlias / addAliases', function () {
        it('registers aliases resolved via get()', function () {
            $container = minimalBuilder()
                ->addService('real', 'value')
                ->addAlias('short', 'real')
                ->build();

            expect($container->get('short'))->toBe('value');
        });

        it('addAliases registers a batch', function () {
            $container = minimalBuilder()
                ->addService('real', 'value')
                ->addAliases(['a' => 'real', 'b' => 'real'])
                ->build();

            expect($container->get('a'))->toBe('value')
                ->and($container->get('b'))->toBe('value');
        });
    });

    describe('addAutowire', function () {
        it('enables the container to construct the class via reflection', function () {
            $container = minimalBuilder()
                ->addAutowire(SimpleService::class)
                ->build();

            expect($container->get(SimpleService::class))->toBeInstanceOf(SimpleService::class);
        });
    });

    describe('addInvokable', function () {
        it('registers the class under its FQN', function () {
            $container = minimalBuilder()
                ->addInvokable(SimpleService::class)
                ->build();

            expect($container->get(SimpleService::class))->toBeInstanceOf(SimpleService::class);
        });

        it('registers under a distinct alias when given two arguments', function () {
            $container = minimalBuilder()
                ->addInvokable('alias', SimpleService::class)
                ->build();

            expect($container->get('alias'))->toBeInstanceOf(SimpleService::class);
        });
    });

    describe('addDelegator', function () {
        it('wires the delegator so it is applied on get()', function () {
            $container = minimalBuilder()
                ->addFactory('svc', fn () => 'base')
                ->addDelegator('svc', fn (string $v) => $v . '-decorated')
                ->build();

            expect($container->get('svc'))->toBe('base-decorated');
        });
    });

    describe('definitions', function () {
        it('accepts a factory definition registered via addService', function () {
            $container = minimalBuilder()
                ->addService('svc', Definition::factory(fn () => 'via-definition'))
                ->build();

            // Definitions registered as services are stored verbatim and
            // resolved at set() time inside build() - see Container::set().
            expect($container->get('svc'))->toBe('via-definition');
        });

        it('builds from an autowire ClassDefinition with explicit constructor params', function () {
            $container = minimalBuilder()
                ->addService('svc', Definition::autowire(ServiceWithParam::class)
                    ->constructor(['value' => 'built']))
                ->build();

            $instance = $container->get('svc');

            expect($instance)->toBeInstanceOf(ServiceWithParam::class)
                ->and($instance->value)->toBe('built');
        });
    });

    describe('default chain behaviour', function () {
        it('resolves unregistered classes via the reflection chain (autowired on demand)', function () {
            $container = minimalBuilder()->build();

            expect($container->get(SimpleService::class))->toBeInstanceOf(SimpleService::class);
        });

        it('throws NotFoundException for an unknown non-class id', function () {
            $container = minimalBuilder()->build();

            expect(fn () => $container->get('some.unknown.id'))
                ->toThrow(NotFoundException::class);
        });
    });

    describe('compilePlans', function () {
        it('uses sparse mode by default and allows complete mode rollback', function () {
            $builder = minimalBuilder();

            $sparse = $builder->compilePlans([ContainerBuilderPlanModeTarget::class]);
            $complete = $builder->compilePlans(
                [ContainerBuilderPlanModeTarget::class],
                PlanCompiler::MODE_COMPLETE,
            );

            expect($sparse['param'][ContainerBuilderPlanModeTarget::class]['__construct'])
                ->toBe([
                    1 => [
                        'kind' => 'componenta.di.autowire',
                        'payload' => ['type' => SimpleService::class],
                    ],
                ])
                ->and($complete['param'])
                ->toBe([]);
        });

        it('does not leak compile-time resolvers into the runtime container', function () {
            $builder = minimalBuilder();

            $builder->compilePlans([ContainerBuilderEntryIdTarget::class]);
            $container = $builder->build();

            $target = $container->make(ContainerBuilderEntryIdTarget::class);

            expect($target->container)->toBe($container);
        });

        it('does not reuse resolver instances across repeated build calls', function () {
            $builder = minimalBuilder();
            $first = $builder->build();
            $second = $builder->build();

            $target = $second->make(ContainerBuilderEntryIdTarget::class);

            expect($target->container)
                ->toBe($second)
                ->not->toBe($first);
        });
    });

    describe('toArray', function () {
        it('exports fluent changes made after configure()', function () {
            $config = new Config([
                ConfigKey::DEPENDENCIES => [
                    ConfigKey::SERVICES => [
                        'from.config' => 'config',
                    ],
                ],
                'app.name' => 'Ophire',
            ]);

            $array = ContainerBuilder::configure($config)
                ->addService('from.builder', 'builder')
                ->toArray();

            expect($array['app.name'])
                ->toBe('Ophire')
                ->and($array[ConfigKey::DEPENDENCIES][ConfigKey::SERVICES]['from.config'])
                ->toBe('config')
                ->and($array[ConfigKey::DEPENDENCIES][ConfigKey::SERVICES]['from.builder'])
                ->toBe('builder');
        });
    });

    describe('container cache', function () {
        it('builds from cached dependencies with the same runtime behaviour', function () {
            $dependencies = [
                ConfigKey::SERVICES => [
                    'cache.value' => 'from-cache',
                ],
                ConfigKey::FACTORIES => [
                    'cached.service' => CacheFactory::class,
                ],
                ConfigKey::ALIASES => [
                    'cached.alias' => 'cached.service',
                ],
                ConfigKey::DELEGATORS => [
                    'cached.service' => CacheDelegator::class,
                ],
                ConfigKey::INVOKABLES => [
                    'simple.alias' => SimpleService::class,
                ],
                ConfigKey::AUTOWIRES => [
                    CacheConsumer::class,
                ],
            ];

            $config = new Config(
                [ConfigKey::DEPENDENCIES => $dependencies],
                new Environment(['APP_ENV' => 'production']),
            );
            $slimConfig = new Config(
                ['app.name' => 'Ophire'],
                $config->environment,
            );
            $cache = [
                'version' => ContainerBuilder::CACHE_VERSION,
                ConfigKey::DEPENDENCIES => ContainerBuilder::normalizeDependencies($dependencies),
            ];

            $fromConfig = ContainerBuilder::configure($config)->build();
            $fromCache = ContainerBuilder::configureFromCache($slimConfig, $cache)->build();

            expect($cache[ConfigKey::DEPENDENCIES][ConfigKey::SERVICES])
                ->toBe(['cache.value' => 'from-cache'])
                ->and($fromCache->get(Config::class)->has(ConfigKey::DEPENDENCIES))
                ->toBeTrue()
                ->and($fromCache->get(Config::class)->get('app.name'))
                ->toBe('Ophire')
                ->and($fromCache->get('cached.service')->value)
                ->toBe($fromConfig->get('cached.service')->value)
                ->toBe('from-cache-decorated')
                ->and($fromCache->get('cached.alias')->value)
                ->toBe($fromConfig->get('cached.alias')->value)
                ->toBe('from-cache')
                ->and($fromCache->get('simple.alias'))
                ->toBeInstanceOf(SimpleService::class)
                ->and($fromCache->get(CacheConsumer::class)->service)
                ->toBeInstanceOf(SimpleService::class);

            $fromCache->set('cached.service', new ServiceWithParam('runtime'));
            expect($fromCache->get('cached.service')->value)->toBe('runtime-decorated');

            $fromCache->alias('late.value', 'cache.value');
            expect($fromCache->get('late.value'))->toBe('from-cache');

            expect($fromCache->make(ServiceWithParam::class, ['value' => 'fresh'])->value)
                ->toBe('fresh');

            $external = new class implements ContainerInterface {
                public function get(string $id): mixed
                {
                    return 'external-value';
                }

                public function has(string $id): bool
                {
                    return $id === 'external.value';
                }
            };

            $fromCache->addContainer($external);
            expect($fromCache->get('external.value'))->toBe('external-value');
        });

        it('rejects unsupported cache versions', function () {
            $config = new Config([]);

            expect(fn () => ContainerBuilder::configureFromCache($config, [
                'version' => ContainerBuilder::CACHE_VERSION + 1,
                ConfigKey::DEPENDENCIES => [],
            ]))->toThrow(InvalidConfigurationException::class);
        });

        it('resolves relative DI plan sidecar paths from the container cache directory', function () {
            $builder = ContainerBuilder::configureFromCache(
                new Config([]),
                [
                    'version' => ContainerBuilder::CACHE_VERSION,
                    ConfigKey::DEPENDENCIES => ContainerBuilder::normalizeDependencies([
                        PlanCompiler::FILE_CONFIG_KEY => 'di-plans.cache.php',
                    ]),
                ],
                __DIR__,
            );

            expect($builder->diPlansFile)->toBe(__DIR__ . '/di-plans.cache.php');
        });
    });
});

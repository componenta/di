<?php

declare(strict_types=1);

use Componenta\DI\Definition\ClassDefinition;
use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Componenta\DI\Definition\Definition;
use Componenta\DI\Definition\FactoryDefinition;
use Componenta\DI\Definition\InvokableDefinition;
use Componenta\DI\Definition\ReferenceDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\LazyServiceFactoryInterface;
use Componenta\DI\NullContainer;
use Componenta\DI\ProxyFactory;
use Componenta\DI\ProxyFactoryInterface;
use Componenta\DI\Resolver\Entry\FactoryResolver;
use Componenta\DI\Tests\Fixture\ServiceWithParam;
use Componenta\DI\Tests\Fixture\SimpleService;
use Psr\Container\ContainerInterface;

function smallContainer(array $entries = []): ContainerInterface
{
    return new class ($entries) implements ContainerInterface {
        public function __construct(private array $entries) {}

        public function get(string $id): mixed
        {
            if (!array_key_exists($id, $this->entries)) {
                throw NotFoundException::forService($id);
            }
            $value = $this->entries[$id];
            return is_callable($value) ? $value() : $value;
        }

        public function has(string $id): bool
        {
            return array_key_exists($id, $this->entries);
        }
    };
}

function makeFactoryResolver(array $factories, ?ContainerInterface $container = null, ?ProxyFactoryInterface $proxyFactory = null): FactoryResolver
{
    return new FactoryResolver(
        $factories,
        $container ?? new NullContainer(),
        $proxyFactory ?? new ProxyFactory(),
    );
}

describe('Resolver\\FactoryResolver', function () {
    describe('can()', function () {
        it('reports true only for registered ids', function () {
            $resolver = makeFactoryResolver(['svc' => fn () => 'v']);

            expect($resolver->can('svc'))->toBeTrue()
                ->and($resolver->can('missing'))->toBeFalse();
        });
    });

    describe('resolve()', function () {
        it('invokes a closure factory with a container value', function () {
            $container = smallContainer();
            $resolver = makeFactoryResolver([
                'svc' => fn (ContainerInterface $c) => [$c, 'produced'],
            ], container: $container);

            $result = $resolver->resolve('svc');

            expect($result[0])->toBeInstanceOf(ContainerValue::class)
                ->and($result[0]->value)->toBe($container)
                ->and($result[1])->toBe('produced');
        });

        it('exposes config to closure factories through the container value', function () {
            $config = new Config(['app' => ['name' => 'Componenta']]);
            $resolver = makeFactoryResolver([
                'svc' => fn (ContainerValue $container): string => $container->config->string(new \Componenta\Config\ConfigPath('app.name')),
            ], container: smallContainer([
                Config::class => $config,
            ]));

            expect($resolver->resolve('svc'))->toBe('Componenta');
        });

        it('passes resolution context as the second factory argument', function () {
            $resolver = makeFactoryResolver([
                'svc' => fn (ContainerInterface $container, array $context): array => [
                    'context' => $context,
                    'container' => $container,
                ],
            ]);

            $result = $resolver->resolve('svc', ['name' => 'Alex', 1 => 'positional']);

            expect($result['context'])->toBe(['name' => 'Alex', 1 => 'positional'])
                ->and($result['container'])->toBeInstanceOf(ContainerValue::class);
        });

        it('unwraps FactoryDefinition and invokes the callable inside', function () {
            $resolver = makeFactoryResolver([
                'svc' => Definition::factory(fn () => 'from-definition'),
            ]);

            expect($resolver->resolve('svc'))->toBe('from-definition');
        });

        it('builds an instance from a ClassDefinition with constructor params', function () {
            $definition = Definition::autowire(ServiceWithParam::class)
                ->constructor(['value' => 'hello']);
            $resolver = makeFactoryResolver(['svc' => $definition]);

            $instance = $resolver->resolve('svc');

            expect($instance)->toBeInstanceOf(ServiceWithParam::class)
                ->and($instance->value)->toBe('hello');
        });

        it('resolves ReferenceDefinition values in ClassDefinition constructor params via the container', function () {
            $container = smallContainer(['value.key' => 'referenced']);
            $definition = Definition::autowire(ServiceWithParam::class)
                ->constructor(['value' => Definition::reference('value.key')]);
            $resolver = makeFactoryResolver(['svc' => $definition], container: $container);

            expect($resolver->resolve('svc')->value)->toBe('referenced');
        });

        it('invokes methodCalls on the constructed instance in registration order', function () {
            $recorder = new class () {
                public array $calls = [];

                public function append(string $v): void
                {
                    $this->calls[] = $v;
                }
            };
            $definition = Definition::autowire($recorder::class)
                ->method('append', ['first'])
                ->method('append', ['second']);
            // NOTE: associative map keyed by method name means the last
            // registration overwrites earlier ones. This test guards that
            // method() replaces per-method params (documented semantics).
            $resolver = makeFactoryResolver(['svc' => $definition]);

            $instance = $resolver->resolve('svc');

            expect($instance->calls)->toBe(['second']);
        });

        it('resolves a string-form factory reference through the container', function () {
            $callable = fn () => 'produced';
            $resolver = makeFactoryResolver(
                ['svc' => 'factory.id'],
                container: smallContainer(['factory.id' => fn () => $callable]),
            );

            expect($resolver->resolve('svc'))->toBe('produced');
        });

        it('resolves [string, method] by fetching the object from the container', function () {
            $service = new class () {
                public function make(ContainerInterface $c): string
                {
                    return 'made';
                }
            };
            $resolver = makeFactoryResolver(
                ['svc' => [$service::class, 'make']],
                container: smallContainer([$service::class => fn () => $service]),
            );

            expect($resolver->resolve('svc'))->toBe('made');
        });

        it('delegates to LazyServiceFactoryInterface::lazy when the factory implements it', function () {
            $lazy = new class () implements LazyServiceFactoryInterface {
                public bool $called = false;
                public ?ContainerInterface $seen = null;

                public function lazy(ContainerInterface $container, ProxyFactoryInterface $proxyFactory, array $context = []): object
                {
                    $this->called = true;
                    $this->seen = $container;
                    return new SimpleService();
                }

                public function __invoke(ContainerInterface $container): object
                {
                    throw new RuntimeException('should not call __invoke when lazy() exists');
                }
            };
            $resolver = makeFactoryResolver(['svc' => $lazy]);

            $result = $resolver->resolve('svc');

            expect($lazy->called)->toBeTrue()
                ->and($lazy->seen)->toBeInstanceOf(ContainerValue::class)
                ->and($result)->toBeInstanceOf(SimpleService::class);
        });

        it('passes resolution context as the third lazy factory argument', function () {
            $lazy = new class () implements LazyServiceFactoryInterface {
                /** @var array<string|int, mixed> */
                public array $seenContext = [];

                public function lazy(ContainerInterface $container, ProxyFactoryInterface $proxyFactory, array $context = []): object
                {
                    $this->seenContext = $context;

                    return new class ($context) {
                        /**
                         * @param array<string|int, mixed> $context
                         */
                        public function __construct(
                            public array $context,
                        ) {}
                    };
                }

                public function __invoke(ContainerInterface $container): object
                {
                    throw new RuntimeException('should not call __invoke when lazy() exists');
                }
            };
            $resolver = makeFactoryResolver(['svc' => $lazy]);

            $result = $resolver->resolve('svc', ['name' => 'lazy']);

            expect($result->context)->toBe(['name' => 'lazy'])
                ->and($lazy->seenContext)->toBe(['name' => 'lazy']);
        });

        it('wraps foreign Throwables from the factory into ResolutionException', function () {
            $boom = new RuntimeException('factory boom');
            $resolver = makeFactoryResolver([
                'svc' => function () use ($boom) { throw $boom; },
            ]);

            try {
                $resolver->resolve('svc');
            } catch (ResolutionException $e) {
                expect($e->getPrevious())->toBe($boom);
                return;
            }

            self::fail('expected ResolutionException');
        });

        it('lets ContainerExceptionInterface exceptions propagate unchanged', function () {
            $original = NotFoundException::forService('inner');
            $resolver = makeFactoryResolver([
                'svc' => function () use ($original) { throw $original; },
            ]);

            try {
                $resolver->resolve('svc');
            } catch (Throwable $e) {
                expect($e)->toBe($original);
                return;
            }

            self::fail('expected container exception to propagate');
        });
    });

    describe('definition support', function () {
        it('supportsDefinition is true for FactoryDefinition and ClassDefinition', function () {
            $resolver = makeFactoryResolver([]);

            expect($resolver->supportsDefinition(new FactoryDefinition(fn () => null)))->toBeTrue()
                ->and($resolver->supportsDefinition(new ClassDefinition(SimpleService::class)))->toBeTrue();
        });

        it('supportsDefinition is false for unrelated definition types', function () {
            $resolver = makeFactoryResolver([]);

            expect($resolver->supportsDefinition(new InvokableDefinition(SimpleService::class)))->toBeFalse()
                ->and($resolver->supportsDefinition(new ReferenceDefinition('x')))->toBeFalse();
        });

        it('setDefinition registers the factory and makes can() return true', function () {
            $resolver = makeFactoryResolver([]);

            $resolver->setDefinition('svc', new FactoryDefinition(fn () => 'v'));

            expect($resolver->can('svc'))->toBeTrue()
                ->and($resolver->resolve('svc'))->toBe('v');
        });

        it('setDefinition throws InvalidConfigurationException for unsupported types', function () {
            $resolver = makeFactoryResolver([]);

            expect(fn () => $resolver->setDefinition('svc', new InvokableDefinition(SimpleService::class)))
                ->toThrow(InvalidConfigurationException::class);
        });
    });
});

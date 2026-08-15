<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Componenta\DI\ConfigKey;
use Componenta\DI\Container;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Definition\FactoryDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\LazyServiceFactoryInterface;
use Componenta\DI\NullContainer;
use Componenta\DI\ProxyFactory;
use Componenta\DI\ProxyFactoryInterface;
use Componenta\DI\Resolver\Entry\FactoryResolver;
use Componenta\DI\Tests\Fixture\SimpleService;
use Psr\Container\ContainerInterface;

function factorySignatureUnscopedClosure(): Closure
{
    return static fn(self $container): SimpleService => new SimpleService();
}

final class FactorySignatureStaticMagic
{
    private static function create(): void {}

    public static function __callStatic(string $name, array $arguments): object
    {
        return new SimpleService();
    }
}

describe('factory callable signature validation', function (): void {
    $resolver = static fn(array $factories, ?ContainerInterface $container = null): FactoryResolver => new FactoryResolver(
        $factories,
        $container ?? new NullContainer(),
        new ProxyFactory(),
    );

    it('accepts callable signatures compatible with the runtime protocol', function () use ($resolver): void {
        $factories = [
            'zero' => static fn(): SimpleService => new SimpleService(),
            'container-value' => static fn(ContainerValue $container): SimpleService => new SimpleService(),
            'container-interface' => static fn(ContainerInterface $container): SimpleService => new SimpleService(),
            'container-union' => static fn(ContainerValue|ContainerInterface $container, array $context): SimpleService => new SimpleService(),
            'container-and-context' => static fn(ContainerInterface $container, array $context): SimpleService => new SimpleService(),
            'iterable-context' => static fn(ContainerValue $container, iterable $context): SimpleService => new SimpleService(),
            'optional-third' => static fn(ContainerValue $container, array $context, mixed $optional = null): SimpleService => new SimpleService(),
            'variadic' => static fn(mixed ...$arguments): SimpleService => new SimpleService(),
        ];

        $factoryResolver = $resolver($factories);

        foreach (array_keys($factories) as $id) {
            expect($factoryResolver->resolve($id))->toBeInstanceOf(SimpleService::class);
        }
    });

    it('keeps shortened userland signatures valid through the fluent builder', function (): void {
        $builder = new ContainerBuilder();
        $builder->addFactory('zero', static fn(): SimpleService => new SimpleService());
        $builder->addFactory(
            'container',
            static fn(ContainerInterface $container): SimpleService => new SimpleService(),
        );

        $container = $builder->build();

        expect($container->get('zero'))->toBeInstanceOf(SimpleService::class)
            ->and($container->get('container'))->toBeInstanceOf(SimpleService::class);
    });

    it('rejects an incompatible concrete factory immediately through the fluent builder', function (): void {
        $builder = new ContainerBuilder();

        expect(fn() => $builder->addFactory(
            'invalid.signature',
            static fn(array $context): SimpleService => new SimpleService(),
        ))->toThrow(InvalidConfigurationException::class, 'incompatible type');

        expect($builder->factories)->toBe([]);
    });

    it('validates FactoryDefinition before declarative configuration unwraps it', function (): void {
        $config = new Config([
            ConfigKey::DEPENDENCIES => [
                ConfigKey::FACTORIES => [
                    'invalid.definition' => new FactoryDefinition(
                        static fn(array $context): SimpleService => new SimpleService(),
                    ),
                ],
            ],
        ]);

        expect(fn() => ContainerBuilder::configure($config))
            ->toThrow(InvalidConfigurationException::class, 'incompatible type');
    });

    it('rejects incompatible concrete callable signatures during registration', function (callable $factory) use ($resolver): void {
        expect(fn() => $resolver(['invalid.signature' => $factory]))
            ->toThrow(InvalidConfigurationException::class);
    })->with([
        'concrete DI container instead of ContainerValue' => [
            static fn(Container $container): SimpleService => new SimpleService(),
        ],
        'context array in the first position' => [
            static fn(array $context): SimpleService => new SimpleService(),
        ],
        'incompatible context type' => [
            static fn(ContainerInterface $container, string $context): SimpleService => new SimpleService(),
        ],
        'third required argument' => [
            static fn(ContainerInterface $container, array $context, string $required): SimpleService => new SimpleService(),
        ],
        'incompatible variadic argument type' => [
            static fn(array ...$arguments): SimpleService => new SimpleService(),
        ],
    ]);

    it('normalizes unresolved callable type scope as invalid configuration', function () use ($resolver): void {
        $factory = factorySignatureUnscopedClosure();

        expect(fn() => $resolver(['invalid.scope' => $factory]))
            ->toThrow(InvalidConfigurationException::class, 'cannot be validated');
    });

    it('validates the callable inside FactoryDefinition', function () use ($resolver): void {
        $definition = new FactoryDefinition(
            static fn(array $context): SimpleService => new SimpleService(),
        );

        expect(fn() => $resolver(['invalid.definition' => $definition]))
            ->toThrow(InvalidConfigurationException::class, 'incompatible type');
    });

    it('rejects an incompatible runtime FactoryDefinition when Container::set registers it', function (): void {
        $container = (new ContainerBuilder())->build();

        expect(fn() => $container->set(
            'invalid.definition',
            new FactoryDefinition(
                static fn(array $context): SimpleService => new SimpleService(),
            ),
        ))->toThrow(InvalidConfigurationException::class, 'incompatible type');
    });

    it('validates a deferred factory after its service id resolves', function () use ($resolver): void {
        $factory = static fn(array $context): SimpleService => new SimpleService();
        $container = new class ($factory) implements ContainerInterface {
            public function __construct(private readonly mixed $factory) {}

            public function get(string $id): mixed
            {
                return $this->factory;
            }

            public function has(string $id): bool
            {
                return $id === 'factory.service';
            }
        };
        $factoryResolver = $resolver(['service' => 'factory.service'], $container);

        expect(fn() => $factoryResolver->resolve('service'))
            ->toThrow(InvalidConfigurationException::class, 'incompatible type');
    });

    it('validates a deferred service-method factory after its service resolves', function () use ($resolver): void {
        $factory = new class () {
            public function create(array $context): SimpleService
            {
                return new SimpleService();
            }
        };
        $container = new class ($factory) implements ContainerInterface {
            public function __construct(private readonly object $factory) {}

            public function get(string $id): mixed
            {
                return $this->factory;
            }

            public function has(string $id): bool
            {
                return $id === 'factory.service';
            }
        };
        $factoryResolver = $resolver([
            'service' => ['factory.service', 'create'],
        ], $container);

        expect(fn() => $factoryResolver->resolve('service'))
            ->toThrow(InvalidConfigurationException::class, 'incompatible type');
    });

    it('preserves service-id precedence for callable-looking strings', function () use ($resolver): void {
        $factory = static fn(ContainerInterface $container): SimpleService => new SimpleService();
        $container = new class ($factory) implements ContainerInterface {
            public function __construct(private readonly mixed $factory) {}

            public function get(string $id): mixed
            {
                return $this->factory;
            }

            public function has(string $id): bool
            {
                return $id === 'strlen';
            }
        };
        $factoryResolver = $resolver(['service' => 'strlen'], $container);

        expect($factoryResolver->resolve('service'))->toBeInstanceOf(SimpleService::class);
    });

    it('preserves service-id precedence for callable strings in FactoryDefinition', function () use ($resolver): void {
        $factory = static fn(ContainerInterface $container): SimpleService => new SimpleService();
        $container = new class ($factory) implements ContainerInterface {
            public function __construct(private readonly mixed $factory) {}

            public function get(string $id): mixed
            {
                return $this->factory;
            }

            public function has(string $id): bool
            {
                return $id === 'strlen';
            }
        };
        $factoryResolver = $resolver([
            'service' => new FactoryDefinition('strlen'),
        ], $container);

        expect($factoryResolver->resolve('service'))->toBeInstanceOf(SimpleService::class);
    });

    it('rejects an incompatible callable-looking string after service lookup misses', function () use ($resolver): void {
        $factoryResolver = $resolver(['service' => 'strlen']);

        expect(fn() => $factoryResolver->resolve('service'))
            ->toThrow(InvalidConfigurationException::class);
    });

    it('does not validate __invoke when LazyServiceFactoryInterface uses lazy instead', function () use ($resolver): void {
        $factory = new class () implements LazyServiceFactoryInterface {
            public function __invoke(array $wrong): object
            {
                throw new RuntimeException('The normal factory path must not be used.');
            }

            public function lazy(
                ContainerInterface $container,
                ProxyFactoryInterface $proxyFactory,
                array $context = [],
            ): object {
                return new SimpleService();
            }
        };

        expect($resolver(['service' => $factory])->resolve('service'))
            ->toBeInstanceOf(SimpleService::class);
    });

    it('keeps magic method callables valid when no concrete method can be reflected', function () use ($resolver): void {
        $factory = new class () {
            public function __call(string $method, array $arguments): object
            {
                return new SimpleService();
            }
        };

        expect($resolver(['service' => [$factory, 'create']])->resolve('service'))
            ->toBeInstanceOf(SimpleService::class);
    });

    it('keeps magic trampoline closures valid', function () use ($resolver): void {
        $factory = new class () {
            private function privateFactory(): void {}

            public function __call(string $method, array $arguments): object
            {
                return new SimpleService();
            }
        };
        $closures = [
            Closure::fromCallable([$factory, 'create']),
            $factory->create(...),
            Closure::fromCallable([$factory, 'privateFactory']),
            $factory->privateFactory(...),
            Closure::fromCallable([FactorySignatureStaticMagic::class, 'create']),
        ];

        foreach ($closures as $closure) {
            expect($resolver(['service' => $closure])->resolve('service'))
                ->toBeInstanceOf(SimpleService::class);
        }
    });

    it('still validates ordinary internal closures with concrete signatures', function () use ($resolver): void {
        $exceptionWithMagic = new class () extends Exception {
            public function __call(string $name, array $arguments): mixed
            {
                return null;
            }
        };
        $internalCallables = [
            Closure::fromCallable('strlen'),
            Closure::fromCallable([new ArrayObject(), 'count']),
            (new ReflectionMethod(Exception::class, '__clone'))->getClosure(new Exception()),
            (new ReflectionMethod(Exception::class, '__clone'))->getClosure($exceptionWithMagic),
        ];

        foreach ($internalCallables as $factory) {
            expect(fn() => $resolver(['service' => $factory]))
                ->toThrow(InvalidConfigurationException::class);
        }
    });
});

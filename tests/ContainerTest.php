<?php

declare(strict_types=1);

require_once __DIR__ . '/Fixture/container_helpers.php';

use Componenta\DI\CallableInvokerInterface;
use Componenta\DI\Definition\Definition;
use Componenta\DI\Exception\CircularDependencyException;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\FactoryInterface;
use Componenta\DI\LazyObjectFactoryInterface;
use Componenta\DI\ProxyFactoryInterface;
use Componenta\DI\Tests\Fixture\ServiceWithParam;
use Componenta\DI\Tests\Fixture\SimpleService;
use Psr\Container\ContainerInterface;

function tinyExternalContainer(array $entries): ContainerInterface
{
    return new class ($entries) implements ContainerInterface {
        public function __construct(private array $entries) {}

        public function get(string $id): mixed
        {
            if (!array_key_exists($id, $this->entries)) {
                throw NotFoundException::forService($id);
            }
            return $this->entries[$id];
        }

        public function has(string $id): bool
        {
            return array_key_exists($id, $this->entries);
        }
    };
}

describe('Container', function () {
    describe('self-registration', function () {
        it('exposes itself under every interface it implements', function () {
            $container = minimalContainer();

            expect($container->get(ContainerInterface::class))->toBe($container)
                ->and($container->get(FactoryInterface::class))->toBe($container)
                ->and($container->get(CallableInvokerInterface::class))->toBe($container)
                ->and($container->get(ProxyFactoryInterface::class))->toBe($container)
                ->and($container->get(LazyObjectFactoryInterface::class))->toBe($container);
        });
    });

    describe('get() / has()', function () {
        it('throws NotFoundException for unknown ids', function () {
            expect(fn () => minimalContainer()->get('unknown'))
                ->toThrow(NotFoundException::class);
        });

        it('returns false from has() for unknown ids', function () {
            expect(minimalContainer()->has('unknown'))->toBeFalse();
        });

        it('returns the value registered via set()', function () {
            $container = minimalContainer();
            $value = new stdClass();

            $container->set('svc', $value);

            expect($container->get('svc'))->toBe($value)
                ->and($container->has('svc'))->toBeTrue();
        });

        it('returns the same instance on repeat get() calls (cached)', function () {
            $container = minimalBuilder()
                ->addFactory('svc', fn () => new stdClass())
                ->build();

            expect($container->get('svc'))->toBe($container->get('svc'));
        });

        it('resolves aliases transparently', function () {
            $container = minimalBuilder()
                ->addFactory('real.service', fn () => 'value')
                ->addAlias('short', 'real.service')
                ->build();

            expect($container->get('short'))->toBe('value')
                ->and($container->has('short'))->toBeTrue();
        });
    });

    describe('set()', function () {
        it('accepts a DefinitionInterface and resolves it on get()', function () {
            $container = minimalContainer();

            $container->set('svc', Definition::factory(fn () => 'from-definition'));

            expect($container->get('svc'))->toBe('from-definition');
        });

        it('keeps registered class definition state stable after later fluent changes', function () {
            $container = minimalContainer();
            $definition = Definition::autowire(ServiceWithParam::class)
                ->constructor(['value' => 'registered']);

            $container->set('svc', $definition);
            $definition->constructor(['value' => 'changed']);

            expect($container->get('svc')->value)->toBe('registered');
        });

        it('throws InvalidConfigurationException for an unsupported definition type', function () {
            $container = minimalContainer();
            $orphan = new class () implements Componenta\DI\Definition\DefinitionInterface {
                public mixed $value { get => null; }
            };

            expect(fn () => $container->set('svc', $orphan))
                ->toThrow(InvalidConfigurationException::class);
        });

        it('invalidates a cached entry when set() runs for the same id', function () {
            $container = minimalBuilder()
                ->addFactory('svc', fn () => 'first')
                ->build();
            expect($container->get('svc'))->toBe('first');

            $container->set('svc', 'replaced');

            expect($container->get('svc'))->toBe('replaced');
        });
    });

    describe('alias()', function () {
        it('registers an alias that resolves to the target entry', function () {
            $container = minimalBuilder()
                ->addService('real', 'value')
                ->build();

            $container->alias('aliased', 'real');

            expect($container->get('aliased'))->toBe('value');
        });

        it('invalidates cached results for the alias name', function () {
            $container = minimalBuilder()
                ->addFactory('a', fn () => 'initial')
                ->addFactory('b', fn () => 'other')
                ->addAlias('alias', 'a')
                ->build();
            expect($container->get('alias'))->toBe('initial');

            $container->alias('alias', 'b');

            expect($container->get('alias'))->toBe('other');
        });
    });

    describe('external containers', function () {
        it('delegates get() to an external container that owns the id', function () {
            $external = tinyExternalContainer(['external.svc' => 'from-outside']);
            $container = minimalContainer();
            $container->addContainer($external);

            expect($container->get('external.svc'))->toBe('from-outside')
                ->and($container->has('external.svc'))->toBeTrue();
        });
    });

    describe('cycle detection', function () {
        it('throws CircularDependencyException when factories form a cycle', function () {
            $container = minimalBuilder()
                ->addFactory('a', fn (ContainerInterface $c) => $c->get('b'))
                ->addFactory('b', fn (ContainerInterface $c) => $c->get('a'))
                ->build();

            expect(fn () => $container->get('a'))
                ->toThrow(CircularDependencyException::class);
        });
    });

    describe('delegators', function () {
        it('applies registered delegators in order to the resolved entry', function () {
            $container = minimalBuilder()
                ->addFactory('counter', fn () => 1)
                ->build();
            $container->delegator('counter', fn (int $v) => $v + 10);
            $container->delegator('counter', fn (int $v) => $v * 2);

            expect($container->get('counter'))->toBe(22);
        });

        it('invalidates cached resolution when a delegator is added', function () {
            $container = minimalBuilder()
                ->addFactory('svc', fn () => 'base')
                ->build();
            expect($container->get('svc'))->toBe('base');

            $container->delegator('svc', fn (string $v) => $v . '-decorated');

            expect($container->get('svc'))->toBe('base-decorated');
        });
    });

    describe('make()', function () {
        it('returns a fresh instance on each call (no caching)', function () {
            $container = minimalBuilder()
                ->addAutowire(SimpleService::class)
                ->build();

            $a = $container->make(SimpleService::class);
            $b = $container->make(SimpleService::class);

            expect($a)->not->toBe($b);
        });

        it('passes user-supplied params to the constructor by name', function () {
            $container = minimalBuilder()
                ->addAutowire(ServiceWithParam::class)
                ->build();

            $instance = $container->make(ServiceWithParam::class, ['value' => 'hello']);

            expect($instance->value)->toBe('hello');
        });

        it('resolves aliases', function () {
            $container = minimalBuilder()
                ->addAutowire(SimpleService::class)
                ->addAlias('service', SimpleService::class)
                ->build();

            expect($container->make('service'))->toBeInstanceOf(SimpleService::class);
        });

        it('does not apply delegators registered on the id', function () {
            $container = minimalBuilder()
                ->addAutowire(SimpleService::class)
                ->build();
            $container->delegator(SimpleService::class, fn ($entry) => 'replaced-by-delegator');

            $instance = $container->make(SimpleService::class);

            expect($instance)->toBeInstanceOf(SimpleService::class);
        });

        it('propagates NotFoundException for a string the resolver chain cannot handle', function () {
            expect(fn () => minimalContainer()->make('not.a.class'))
                ->toThrow(NotFoundException::class);
        });
    });

    describe('call()', function () {
        it('invokes the callable with DI-resolved parameters', function () {
            $container = minimalBuilder()
                ->addService('value', 21)
                ->build();

            // callable is called with explicit override, not from container
            $result = $container->call(fn (int $value) => $value * 2, ['value' => 21]);

            expect($result)->toBe(42);
        });
    });
});

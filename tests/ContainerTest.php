<?php

declare(strict_types=1);

namespace Componenta\DI\Tests;

require_once __DIR__ . '/Fixture/container_helpers.php';

use Componenta\DI\CallableInvokerInterface;
use Componenta\DI\CallableResolverInterface;
use Componenta\DI\Exception\CircularDependencyException;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\FactoryInterface;
use Componenta\DI\ProxyFactoryInterface;
use Componenta\DI\Tests\Fixture\ServiceWithParam;
use Componenta\DI\Tests\Fixture\SimpleService;
use Psr\Container\ContainerInterface;

final class UnsupportedDefinition implements \Componenta\DI\Definition\DefinitionInterface
{
    public mixed $value {
        get => 'unsupported';
    }
}

describe('Container', function () {
    describe('get() / has()', function () {
        it('returns the same instance on repeat get() calls (cached)', function () {
            $container = minimalBuilder()->build();

            $a = $container->get(SimpleService::class);
            $b = $container->get(SimpleService::class);

            expect($a)->toBe($b);
        });

        it('resolves aliases transparently', function () {
            $container = minimalBuilder()
                ->addAlias('alias', SimpleService::class)
                ->build();

            expect($container->get('alias'))->toBeInstanceOf(SimpleService::class)
                ->and($container->has('alias'))->toBeTrue();
        });

        it('returns false from has() for unknown ids', function () {
            expect(minimalContainer()->has('no.such.id'))->toBeFalse();
        });

        it('throws NotFoundException for unknown ids', function () {
            expect(fn() => minimalContainer()->get('no.such.id'))
                ->toThrow(NotFoundException::class);
        });

        it('reports ids owned by an external container from has()', function () {
            $external = new class () implements ContainerInterface {
                public function get(string $id): mixed
                {
                    return 'external';
                }

                public function has(string $id): bool
                {
                    return $id === 'external.present';
                }
            };
            $container = minimalContainer();
            $container->addContainer($external);

            expect($container->has('external.present'))->toBeTrue()
                ->and($container->has('external.missing'))->toBeFalse();
        });

        it('does not hide programming errors raised by external containers from has()', function () {
            $external = new class () implements ContainerInterface {
                public function get(string $id): mixed
                {
                    throw new \LogicException('not used');
                }

                public function has(string $id): bool
                {
                    throw new \TypeError('broken external container');
                }
            };
            $container = minimalContainer();
            $container->addContainer($external);

            expect(fn() => $container->has('external.broken'))
                ->toThrow(\TypeError::class, 'broken external container');
        });
    });

    describe('self-registration', function () {
        it('exposes itself under every interface it implements', function () {
            $container = minimalContainer();

            expect($container->get(ContainerInterface::class))->toBe($container)
                ->and($container->get(FactoryInterface::class))->toBe($container)
                ->and($container->get(CallableInvokerInterface::class))->toBe($container)
                ->and($container->get(CallableResolverInterface::class))->toBeInstanceOf(CallableResolverInterface::class)
                ->and($container->get(ProxyFactoryInterface::class))->toBe($container);
        });
    });

    describe('set()', function () {
        it('returns the value registered via set()', function () {
            $container = minimalContainer();
            $object = new \stdClass();
            $container->set('obj', $object);

            expect($container->get('obj'))->toBe($object)
                ->and($container->has('obj'))->toBeTrue();
        });

        it('accepts a DefinitionInterface and resolves it on get()', function () {
            $container = minimalContainer();
            $container->set(
                'defined',
                \Componenta\DI\Definition\Definition::factory(
                    static fn() => new SimpleService(),
                ),
            );

            expect($container->get('defined'))->toBeInstanceOf(SimpleService::class);
        });

        it('keeps registered class definition state stable after later fluent changes', function () {
            $definition = \Componenta\DI\Definition\ClassDefinition::create(
                ServiceWithParam::class,
            )->constructor(['value' => 'registered']);
            $container = minimalContainer();
            $container->set('class-definition', $definition);
            $changed = $definition->constructor(['value' => 'changed-later']);

            expect($changed)->not->toBe($definition)
                ->and($container->get('class-definition')->value)->toBe('registered');
        });

        it('throws InvalidConfigurationException for an unsupported definition type', function () {
            $container = minimalContainer();

            expect(fn() => $container->set('unsupported', new UnsupportedDefinition()))
                ->toThrow(InvalidConfigurationException::class);
        });

        it('invalidates a cached entry when set() runs for the same id', function () {
            $container = minimalBuilder()
                ->addFactory('svc', fn() => 'first')
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
                ->addFactory('a', fn() => 'initial')
                ->addFactory('b', fn() => 'other')
                ->addAlias('alias', 'a')
                ->build();
            expect($container->get('alias'))->toBe('initial');

            $container->alias('alias', 'b');

            expect($container->get('alias'))->toBe('other');
        });
    });

    describe('cycle detection', function () {
        it('throws CircularDependencyException with the public resolution chain when factories form a cycle', function () {
            $container = minimalBuilder()
                ->addFactory('a', fn(ContainerInterface $c) => $c->get('b'))
                ->addFactory('b', fn(ContainerInterface $c) => $c->get('a'))
                ->build();

            try {
                $container->get('a');
            } catch (CircularDependencyException $exception) {
                expect($exception->chain)->toBe(['a', 'b', 'a']);

                return;
            }

            self::fail('expected CircularDependencyException');
        });
    });

    describe('delegators', function () {
        it('applies registered delegators in order to the resolved entry', function () {
            $container = minimalBuilder()
                ->addFactory('counter', fn() => 1)
                ->build();
            $container->delegator('counter', fn(int $v) => $v + 10);
            $container->delegator('counter', fn(int $v) => $v * 2);

            expect($container->get('counter'))->toBe(22);
        });

        it('invalidates cached resolution when a delegator is added', function () {
            $container = minimalBuilder()
                ->addFactory('svc', fn() => 'base')
                ->build();
            expect($container->get('svc'))->toBe('base');

            $container->delegator('svc', fn(string $v) => $v . '-decorated');

            expect($container->get('svc'))->toBe('base-decorated');
        });
    });

    describe('make()', function () {
        it('returns a fresh instance on each call (no caching)', function () {
            $container = minimalBuilder()->build();

            $a = $container->make(SimpleService::class);
            $b = $container->make(SimpleService::class);

            expect($a)->not->toBe($b);
        });

        it('passes user-supplied params to the constructor by name', function () {
            $container = minimalBuilder()->build();

            $instance = $container->make(ServiceWithParam::class, ['value' => 'hello']);

            expect($instance->value)->toBe('hello');
        });

        it('resolves aliases', function () {
            $container = minimalBuilder()
                ->addAlias('factory.alias', SimpleService::class)
                ->build();

            expect($container->make('factory.alias'))->toBeInstanceOf(SimpleService::class);
        });

        it('does not apply delegators registered on the id', function () {
            $container = minimalBuilder()
                ->addFactory('factory.service', fn() => new SimpleService())
                ->build();
            $container->delegator('factory.service', fn() => throw new \RuntimeException('must not run'));

            expect($container->make('factory.service'))->toBeInstanceOf(SimpleService::class);
        });

        it('does not consult external containers', function () {
            $external = new class () implements ContainerInterface {
                public function get(string $id): mixed
                {
                    throw new \RuntimeException('make() must not consult external containers');
                }

                public function has(string $id): bool
                {
                    return $id === SimpleService::class;
                }
            };
            $container = minimalContainer();
            $container->addContainer($external);

            expect($container->make(SimpleService::class))->toBeInstanceOf(SimpleService::class);
        });

        it('propagates NotFoundException for a string the resolver chain cannot handle', function () {
            expect(fn() => minimalContainer()->make('no.such.factory'))
                ->toThrow(NotFoundException::class);
        });
    });

    describe('call()', function () {
        it('invokes the callable with DI-resolved parameters', function () {
            $container = minimalContainer();

            $result = $container->call(
                fn(SimpleService $svc, string $name) => [$svc, $name],
                ['name' => 'test'],
            );

            expect($result[0])->toBeInstanceOf(SimpleService::class)
                ->and($result[1])->toBe('test');
        });
    });
});

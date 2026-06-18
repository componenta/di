<?php

declare(strict_types=1);

use Componenta\DI\CallableResolver;
use Componenta\DI\Exception\InvalidCallableException;
use Componenta\DI\NullContainer;
require_once __DIR__ . '/Fixture/functions.php';

use Componenta\DI\Tests\Fixture\InvokableService;
use Componenta\DI\Tests\Fixture\NonInvokableService;
use Componenta\DI\Tests\Fixture\ServiceWithMethods;
use Psr\Container\ContainerInterface;

function mapContainer(array $entries): ContainerInterface
{
    return new class ($entries) implements ContainerInterface {
        public function __construct(private array $entries) {}

        public function get(string $id): mixed
        {
            if (!array_key_exists($id, $this->entries)) {
                throw new RuntimeException("no entry: $id");
            }
            return $this->entries[$id];
        }

        public function has(string $id): bool
        {
            return array_key_exists($id, $this->entries);
        }
    };
}

describe('CallableResolver', function () {
    describe('closures and callable objects', function () {
        it('returns a Closure as-is', function () {
            $closure = fn () => 'ok';

            $resolver = new CallableResolver(new NullContainer());

            expect($resolver->resolve($closure))->toBe($closure);
        });

        it('wraps an invokable object in a first-class callable that forwards to __invoke', function () {
            $invokable = new InvokableService();
            $resolved = (new CallableResolver(new NullContainer()))->resolve($invokable);

            expect($resolved)->toBeCallable()
                ->and($resolved('!'))->toBe('invoked!');
        });

        it('wraps a [object, method] array callable', function () {
            $service = new ServiceWithMethods();
            $resolved = (new CallableResolver(new NullContainer()))->resolve([$service, 'instanceMethod']);

            expect($resolved(7))->toBe('instance:7');
        });
    });

    describe('Class::method strings', function () {
        it('resolves a static method without consulting the container', function () {
            $container = mapContainer([]);
            $resolver = new CallableResolver($container);

            $resolved = $resolver->resolve(ServiceWithMethods::class . '::staticMethod');

            expect($resolved(3))->toBe('static:3');
        });

        it('resolves an instance method by fetching the class instance from the container', function () {
            $service = new ServiceWithMethods();
            $resolver = new CallableResolver(mapContainer([ServiceWithMethods::class => $service]));

            $resolved = $resolver->resolve(ServiceWithMethods::class . '::instanceMethod');

            expect($resolved(5))->toBe('instance:5');
        });

        it('throws when the class in Class::method does not exist', function () {
            $resolver = new CallableResolver(new NullContainer());

            expect(fn () => $resolver->resolve('NoSuchClass::someMethod'))
                ->toThrow(InvalidCallableException::class);
        });

        it('throws with forMethod variant when the method is missing', function () {
            $resolver = new CallableResolver(new NullContainer());

            expect(fn () => $resolver->resolve(ServiceWithMethods::class . '::missing'))
                ->toThrow(InvalidCallableException::class, 'missing');
        });

        it('throws when an instance method is requested but the class is not in the container', function () {
            $resolver = new CallableResolver(mapContainer([]));

            expect(fn () => $resolver->resolve(ServiceWithMethods::class . '::instanceMethod'))
                ->toThrow(InvalidCallableException::class);
        });
    });

    describe('plain string resolution', function () {
        it('returns a callable service from the container', function () {
            $invokable = new InvokableService();
            $resolver = new CallableResolver(mapContainer(['handler' => $invokable]));

            $resolved = $resolver->resolve('handler');

            expect($resolved('-x'))->toBe('invoked-x');
        });

        it('throws when the container entry is not callable', function () {
            $resolver = new CallableResolver(mapContainer(['plain' => new NonInvokableService()]));

            expect(fn () => $resolver->resolve('plain'))
                ->toThrow(InvalidCallableException::class, 'not invokable');
        });

        it('falls back to a plain global function when no service is registered', function () {
            $resolver = new CallableResolver(mapContainer([]));

            $resolved = $resolver->resolve('Componenta\\DI\\Tests\\Fixture\\globalCallableFixture');

            expect($resolved(4))->toBe(8);
        });

        it('reports an existing class as a missing service (needs container wiring)', function () {
            $resolver = new CallableResolver(mapContainer([]));

            expect(fn () => $resolver->resolve(InvokableService::class))
                ->toThrow(InvalidCallableException::class);
        });

        it('throws for an unknown string that is neither service nor function nor class', function () {
            $resolver = new CallableResolver(mapContainer([]));

            expect(fn () => $resolver->resolve('totally.unknown.token'))
                ->toThrow(InvalidCallableException::class);
        });
    });

    describe('[class, method] arrays', function () {
        it('rejects arrays that are not exactly length 2', function () {
            $resolver = new CallableResolver(new NullContainer());

            expect(fn () => $resolver->resolve([ServiceWithMethods::class]))
                ->toThrow(InvalidCallableException::class);
        });

        it('resolves [class-string, staticMethod] without the container', function () {
            $resolver = new CallableResolver(mapContainer([]));

            $resolved = $resolver->resolve([ServiceWithMethods::class, 'staticMethod']);

            expect($resolved(2))->toBe('static:2');
        });

        it('resolves [class-string, instanceMethod] via container lookup', function () {
            $service = new ServiceWithMethods();
            $resolver = new CallableResolver(mapContainer([ServiceWithMethods::class => $service]));

            $resolved = $resolver->resolve([ServiceWithMethods::class, 'instanceMethod']);

            expect($resolved(9))->toBe('instance:9');
        });

        it('throws when [class-string, method] targets an instance method but the container has no entry', function () {
            $resolver = new CallableResolver(mapContainer([]));

            expect(fn () => $resolver->resolve([ServiceWithMethods::class, 'instanceMethod']))
                ->toThrow(InvalidCallableException::class);
        });

        it('throws when the class part does not exist', function () {
            $resolver = new CallableResolver(new NullContainer());

            expect(fn () => $resolver->resolve(['NoSuchClass', 'someMethod']))
                ->toThrow(InvalidCallableException::class);
        });

        it('throws when the method does not exist on the object', function () {
            $resolver = new CallableResolver(new NullContainer());

            expect(fn () => $resolver->resolve([new ServiceWithMethods(), 'nope']))
                ->toThrow(InvalidCallableException::class);
        });

        it('throws for arrays whose first element is neither object nor string', function () {
            $resolver = new CallableResolver(new NullContainer());

            expect(fn () => $resolver->resolve([42, 'foo']))
                ->toThrow(InvalidCallableException::class);
        });
    });

    describe('unsupported input types', function () {
        it('throws InvalidCallableException for integers', function () {
            expect(fn () => (new CallableResolver(new NullContainer()))->resolve(123))
                ->toThrow(InvalidCallableException::class);
        });
    });
});

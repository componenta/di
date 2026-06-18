<?php

declare(strict_types=1);

use Componenta\DI\CallableResolverInterface;
use Componenta\DI\DelegatorRegistry;
use Componenta\DI\Exception\DelegatorException;
use Componenta\DI\Exception\NotFoundException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;

function stubContainer(): ContainerInterface
{
    return new class () implements ContainerInterface {
        public function get(string $id): mixed { return null; }
        public function has(string $id): bool { return false; }
    };
}

function recordingResolver(array $map = []): CallableResolverInterface
{
    return new class ($map) implements CallableResolverInterface {
        public int $calls = 0;

        /** @param array<string, callable> $map */
        public function __construct(private array $map) {}

        public function resolve(mixed $callable): callable
        {
            $this->calls++;

            if (is_string($callable) && isset($this->map[$callable])) {
                return $this->map[$callable];
            }

            throw new RuntimeException('unresolvable callable: ' . var_export($callable, true));
        }
    };
}

describe('DelegatorRegistry', function () {
    describe('register() / has()', function () {
        it('reports no delegators for an unregistered id', function () {
            $registry = new DelegatorRegistry(recordingResolver());

            expect($registry->has('svc'))->toBeFalse();
        });

        it('reports true after a delegator is registered', function () {
            $registry = new DelegatorRegistry(recordingResolver());

            $registry->register('svc', fn ($e) => $e);

            expect($registry->has('svc'))->toBeTrue();
        });
    });

    describe('apply()', function () {
        it('returns the entry unchanged when no delegators are registered', function () {
            $registry = new DelegatorRegistry(recordingResolver());
            $entry = new stdClass();

            $result = $registry->apply('svc', $entry, stubContainer());

            expect($result)->toBe($entry);
        });

        it('passes entry and container to the delegator and returns its result', function () {
            $registry = new DelegatorRegistry(recordingResolver());
            $received = null;
            $registry->register('svc', function ($entry, $container) use (&$received) {
                $received = [$entry, $container];
                return 'decorated';
            });

            $container = stubContainer();
            $result = $registry->apply('svc', 'original', $container);

            expect($result)->toBe('decorated')
                ->and($received)->toBe(['original', $container]);
        });

        it('applies delegators in registration order, threading the return value', function () {
            $registry = new DelegatorRegistry(recordingResolver());
            $registry->register('svc', fn ($e) => $e . '-1');
            $registry->register('svc', fn ($e) => $e . '-2');
            $registry->register('svc', fn ($e) => $e . '-3');

            expect($registry->apply('svc', 'base', stubContainer()))
                ->toBe('base-1-2-3');
        });

        it('uses a Closure delegator directly without going through the callable resolver', function () {
            $resolver = recordingResolver();
            $registry = new DelegatorRegistry($resolver);
            $registry->register('svc', fn ($e) => $e);

            $registry->apply('svc', 'v', stubContainer());

            expect($resolver->calls)->toBe(0);
        });

        it('uses an already-callable non-Closure delegator directly', function () {
            $resolver = recordingResolver();
            $registry = new DelegatorRegistry($resolver);
            $invokable = new class () {
                public function __invoke($entry) { return $entry . '-invoked'; }
            };

            $registry->register('svc', $invokable);

            expect($registry->apply('svc', 'v', stubContainer()))->toBe('v-invoked')
                ->and($resolver->calls)->toBe(0);
        });

        it('resolves non-callable registrations via the CallableResolver', function () {
            $resolver = recordingResolver([
                'my.delegator' => fn ($e) => $e . '-resolved',
            ]);
            $registry = new DelegatorRegistry($resolver);
            $registry->register('svc', 'my.delegator');

            $result = $registry->apply('svc', 'v', stubContainer());

            expect($result)->toBe('v-resolved')
                ->and($resolver->calls)->toBe(1);
        });

        it('caches resolved callables across repeated apply() calls', function () {
            $resolver = recordingResolver([
                'my.delegator' => fn ($e) => $e,
            ]);
            $registry = new DelegatorRegistry($resolver);
            $registry->register('svc', 'my.delegator');

            $registry->apply('svc', 'v', stubContainer());
            $registry->apply('svc', 'v', stubContainer());
            $registry->apply('svc', 'v', stubContainer());

            expect($resolver->calls)->toBe(1);
        });

        it('re-resolves after register() invalidates the cache', function () {
            $resolver = recordingResolver([
                'my.delegator' => fn ($e) => $e,
            ]);
            $registry = new DelegatorRegistry($resolver);
            $registry->register('svc', 'my.delegator');
            $registry->apply('svc', 'v', stubContainer());

            $registry->register('svc', fn ($e) => $e);
            $registry->apply('svc', 'v', stubContainer());

            // New chain (string + closure) must be normalised again => +1 call.
            expect($resolver->calls)->toBe(2);
        });

        it('re-resolves after invalidate() drops the cache', function () {
            $resolver = recordingResolver([
                'my.delegator' => fn ($e) => $e,
            ]);
            $registry = new DelegatorRegistry($resolver);
            $registry->register('svc', 'my.delegator');
            $registry->apply('svc', 'v', stubContainer());

            $registry->invalidate('svc');
            $registry->apply('svc', 'v', stubContainer());

            expect($resolver->calls)->toBe(2);
        });

        it('keeps raw registrations on invalidate(); apply still runs the delegator', function () {
            $registry = new DelegatorRegistry(recordingResolver());
            $registry->register('svc', fn ($e) => $e . '-x');

            $registry->invalidate('svc');

            expect($registry->has('svc'))->toBeTrue()
                ->and($registry->apply('svc', 'v', stubContainer()))->toBe('v-x');
        });

        it('wraps a delegator\'s foreign exception in DelegatorException with entry id and previous', function () {
            $registry = new DelegatorRegistry(recordingResolver());
            $boom = new RuntimeException('boom');
            $registry->register('svc', function () use ($boom) {
                throw $boom;
            });

            try {
                $registry->apply('svc', 'v', stubContainer());
            } catch (DelegatorException $e) {
                expect($e->entryId)->toBe('svc')
                    ->and($e->getPrevious())->toBe($boom);
                return;
            }

            self::fail('expected DelegatorException');
        });

        it('lets ContainerExceptionInterface exceptions propagate unchanged', function () {
            $registry = new DelegatorRegistry(recordingResolver());
            $original = NotFoundException::forService('other');
            $registry->register('svc', function () use ($original) {
                throw $original;
            });

            try {
                $registry->apply('svc', 'v', stubContainer());
            } catch (Throwable $caught) {
                expect($caught)->toBeInstanceOf(ContainerExceptionInterface::class)
                    ->and($caught)->toBe($original)
                    ->and($caught)->not->toBeInstanceOf(DelegatorException::class);
                return;
            }

            self::fail('expected the container exception to propagate');
        });

        it('wraps a resolution-time foreign exception in DelegatorException', function () {
            $failing = new class () implements CallableResolverInterface {
                public function resolve(mixed $callable): callable
                {
                    throw new RuntimeException('cannot resolve');
                }
            };
            $registry = new DelegatorRegistry($failing);
            $registry->register('svc', 'unresolvable');

            expect(fn () => $registry->apply('svc', 'v', stubContainer()))
                ->toThrow(DelegatorException::class);
        });
    });
});

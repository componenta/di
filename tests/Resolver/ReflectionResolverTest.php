<?php

declare(strict_types=1);

use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\ProxyFactory;
use Componenta\DI\ProxyFactoryInterface;
use Componenta\DI\Resolver\Entry\InstantiatorInterface;
use Componenta\DI\Resolver\Entry\PostInitializerInterface;
use Componenta\DI\Resolver\Entry\PropertyInjectorInterface;
use Componenta\DI\Resolver\Entry\ReflectionResolver;
use Componenta\DI\Tests\Fixture\SimpleService;
use Componenta\DI\Tests\Fixture\ServiceWithoutConstructor;

function recordingInstantiator(?callable $creator = null): InstantiatorInterface
{
    return new class ($creator) implements InstantiatorInterface {
        public int $createCalls = 0;
        public int $initializeCalls = 0;
        public array $createContexts = [];
        public array $initializeContexts = [];

        public function __construct(private mixed $creator) {}

        public function create(ReflectionClass $reflector, array $context = []): object
        {
            $this->createCalls++;
            $this->createContexts[] = $context;
            return $this->creator !== null
                ? ($this->creator)($reflector, $context)
                : $reflector->newInstance();
        }

        public function initialize(object $entry, ReflectionClass $reflector, array $context = []): void
        {
            $this->initializeCalls++;
            $this->initializeContexts[] = $context;
        }
    };
}

function recordingInjector(): PropertyInjectorInterface
{
    return new class () implements PropertyInjectorInterface {
        public array $injectedInto = [];

        public function inject(ReflectionClass $reflector, object $entry, array $context = []): void
        {
            $this->injectedInto[] = [$reflector->getName(), $entry, $context];
        }
    };
}

function recordingPostInit(): PostInitializerInterface
{
    return new class () implements PostInitializerInterface {
        public array $runFor = [];

        public function run(ReflectionClass $reflector, object $entry, array $context = []): void
        {
            $this->runFor[] = [$reflector->getName(), $entry, $context];
        }
    };
}

describe('Resolver\\Entry\\ReflectionResolver', function () {
    describe('can()', function () {
        it('returns true for an instantiable class', function () {
            $resolver = new ReflectionResolver(
                recordingInstantiator(),
                recordingInjector(),
                recordingPostInit(),
                new ProxyFactory(),
            );

            expect($resolver->can(SimpleService::class))->toBeTrue();
        });

        it('returns false for non-existent classes', function () {
            $resolver = new ReflectionResolver(
                recordingInstantiator(),
                recordingInjector(),
                recordingPostInit(),
                new ProxyFactory(),
            );

            expect($resolver->can('NoSuch\\Class'))->toBeFalse();
        });

        it('returns false for non-instantiable types (interfaces)', function () {
            $resolver = new ReflectionResolver(
                recordingInstantiator(),
                recordingInjector(),
                recordingPostInit(),
                new ProxyFactory(),
            );

            expect($resolver->can(Psr\Log\LoggerInterface::class))->toBeFalse();
        });
    });

    describe('eager strategy (no attribute)', function () {
        it('orchestrates instantiator -> injector -> post-init in order', function () {
            $sequence = [];
            $inst = new class (\Componenta\DI\Tests\Fixture\SimpleService::class, $sequence) implements InstantiatorInterface {
                public function __construct(private string $class, public array &$sequence) {}

                public function create(ReflectionClass $reflector, array $context = []): object
                {
                    $this->sequence[] = 'create';
                    return $reflector->newInstance();
                }

                public function initialize(object $entry, ReflectionClass $reflector, array $context = []): void
                {
                    $this->sequence[] = 'initialize';
                }
            };
            $inj = new class ($sequence) implements PropertyInjectorInterface {
                public function __construct(public array &$sequence) {}

                public function inject(ReflectionClass $reflector, object $entry, array $context = []): void
                {
                    $this->sequence[] = 'inject';
                }
            };
            $post = new class ($sequence) implements PostInitializerInterface {
                public function __construct(public array &$sequence) {}

                public function run(ReflectionClass $reflector, object $entry, array $context = []): void
                {
                    $this->sequence[] = 'post';
                }
            };

            $resolver = new ReflectionResolver($inst, $inj, $post, new ProxyFactory());
            $resolver->resolve(SimpleService::class);

            expect($inst->sequence)->toBe(['create', 'inject', 'post']);
        });

        it('forwards the context to each collaborator', function () {
            $inst = recordingInstantiator();
            $inj = recordingInjector();
            $post = recordingPostInit();
            $resolver = new ReflectionResolver($inst, $inj, $post, new ProxyFactory());

            $resolver->resolve(SimpleService::class, ['ctx' => 1]);

            expect($inst->createContexts[0])->toBe(['ctx' => 1])
                ->and($inj->injectedInto[0][2])->toBe(['ctx' => 1])
                ->and($post->runFor[0][2])->toBe(['ctx' => 1]);
        });

        it('returns the instance produced by the instantiator', function () {
            $specific = new SimpleService();
            $inst = recordingInstantiator(fn () => $specific);
            $resolver = new ReflectionResolver($inst, recordingInjector(), recordingPostInit(), new ProxyFactory());

            expect($resolver->resolve(SimpleService::class))->toBe($specific);
        });
    });

    describe('lazy strategy (#[Lazy] attribute)', function () {
        it('wraps the return in a lazy ghost of the target class', function () {
            $lazyClass = new #[Lazy] class () {
                public bool $constructed = false;

                public function __construct()
                {
                    $this->constructed = true;
                }
            };
            $fqcn = $lazyClass::class;

            $inst = recordingInstantiator();
            $resolver = new ReflectionResolver($inst, recordingInjector(), recordingPostInit(), new ProxyFactory());

            $result = $resolver->resolve($fqcn);

            // Lazy ghost: instance is of the target class, but the
            // initializer has not run yet - no create/initialize call.
            expect($result)->toBeInstanceOf($fqcn)
                ->and($inst->createCalls)->toBe(0)
                ->and($inst->initializeCalls)->toBe(0);
        });

        it('runs the initialize/inject/post pipeline on first observable access', function () {
            $lazyClass = new #[Lazy] class () {
                public string $touched = '';
            };
            $fqcn = $lazyClass::class;

            $inst = recordingInstantiator();
            $inj = recordingInjector();
            $post = recordingPostInit();
            $resolver = new ReflectionResolver($inst, $inj, $post, new ProxyFactory());

            $result = $resolver->resolve($fqcn);
            // Touch a property to force ghost initialisation
            $result->touched = 'yes';

            expect($inst->initializeCalls)->toBe(1)
                ->and($inj->injectedInto)->toHaveCount(1)
                ->and($post->runFor)->toHaveCount(1);
        });
    });

    describe('strategy caching', function () {
        it('memoises the strategy decision per class', function () {
            $inst = recordingInstantiator();
            $resolver = new ReflectionResolver($inst, recordingInjector(), recordingPostInit(), new ProxyFactory());

            $resolver->resolve(SimpleService::class);
            $resolver->resolve(SimpleService::class);

            // Eager strategy invokes create once per resolve, cached strategy
            // doesn't add extra calls. This test guards that the cache
            // doesn't accidentally skip or duplicate creation.
            expect($inst->createCalls)->toBe(2);
        });
    });
});

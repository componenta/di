<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\DI\Definition\Definition;
use Componenta\DI\Exception\ConcurrentResolutionException;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Fiber;
use Psr\Container\ContainerInterface;
use RuntimeException;
use WeakReference;

function v5DeferredDelegatorNative(string $entry, ContainerInterface $container): string
{
    return $entry . ':native';
}

function appendDeferredResult(callable $entry, string $value, string $suffix): string
{
    $result = $entry($value);
    if (!is_string($result)) {
        throw new RuntimeException('The deferred delegator must return a string.');
    }

    return $result . $suffix;
}

final readonly class RuntimeDefinitionOwnershipValue
{
    public function __construct(public string $source) {}
}

test('shared resolution distinguishes concurrent Fiber ownership from dependency cycles', function (): void {
    $builds = 0;
    $container = (new ContainerBuilder())
        ->addFactory('fiber.shared', static function () use (&$builds): object {
            ++$builds;
            Fiber::suspend('factory-suspended');
            return new \stdClass();
        })
        ->build();

    $operation = static function () use ($container): object {
        $entry = $container->get('fiber.shared');
        if (!is_object($entry)) {
            throw new RuntimeException('The Fiber factory must return an object.');
        }

        return $entry;
    };
    $first = new Fiber($operation);
    $second = new Fiber($operation);

    expect($first->start())->toBe('factory-suspended')
        ->and(fn() => $second->start())
        ->toThrow(ConcurrentResolutionException::class, 'another execution context');

    $first->resume();
    $resolved = $first->getReturn();

    expect($builds)->toBe(1)
        ->and($container->get('fiber.shared'))->toBe($resolved);
});

test('abandoned suspended Fiber releases shared resolution ownership', function (): void {
    $builds = 0;
    $container = (new ContainerBuilder())
        ->addFactory('fiber.abandoned', static function () use (&$builds): object {
            ++$builds;
            if ($builds === 1) {
                Fiber::suspend('factory-suspended');
            }
            return new \stdClass();
        })
        ->build();

    $fiber = new Fiber(static function () use ($container): object {
        $entry = $container->get('fiber.abandoned');
        if (!is_object($entry)) {
            throw new RuntimeException('The Fiber factory must return an object.');
        }

        return $entry;
    });
    $reference = WeakReference::create($fiber);

    expect($fiber->start())->toBe('factory-suspended');

    unset($fiber);
    gc_collect_cycles();

    expect($reference->get())->toBeNull()
        ->and($container->get('fiber.abandoned'))->toBeInstanceOf(\stdClass::class)
        ->and($builds)->toBe(2);
});

test('changing an alias invalidates entries decorated through that deferred delegator', function (): void {
    $container = (new ContainerBuilder())->build();

    $container->set('handler.first', static fn(string $entry): string => $entry . ':first');
    $container->set('handler.second', static fn(string $entry): string => $entry . ':second');
    $container->alias('handler', 'handler.first');
    $container->set('service', 'base');
    $container->delegator('service', 'handler');

    expect($container->get('service'))->toBe('base:first');

    $container->alias('handler', 'handler.second');

    expect($container->get('service'))->toBe('base:second');
});

test('deferred delegator invalidation propagates through the complete dependency graph', function (): void {
    $container = (new ContainerBuilder())->build();

    $container->set(
        'handler.inner',
        static fn(callable $entry): callable =>
            static fn(string $value): string => appendDeferredResult($entry, $value, ':inner-1'),
    );
    $container->set(
        'handler.outer',
        static fn(string $value): string => $value . ':outer',
    );
    $container->delegator('handler.outer', 'handler.inner');
    $container->set('service.transitive', 'base');
    $container->delegator('service.transitive', 'handler.outer');

    expect($container->get('service.transitive'))->toBe('base:outer:inner-1');

    $container->set(
        'handler.inner',
        static fn(callable $entry): callable =>
            static fn(string $value): string => appendDeferredResult($entry, $value, ':inner-2'),
    );

    expect($container->get('service.transitive'))->toBe('base:outer:inner-2');
});

test('transitive deferred dependencies follow alias retargeting', function (): void {
    $container = (new ContainerBuilder())->build();

    $container->set(
        'handler.inner.first',
        static fn(callable $entry): callable =>
            static fn(string $value): string => appendDeferredResult($entry, $value, ':first'),
    );
    $container->set(
        'handler.inner.second',
        static fn(callable $entry): callable =>
            static fn(string $value): string => appendDeferredResult($entry, $value, ':second'),
    );
    $container->alias('handler.inner.alias', 'handler.inner.first');
    $container->set('handler.alias.outer', static fn(string $value): string => $value . ':outer');
    $container->delegator('handler.alias.outer', 'handler.inner.alias');
    $container->set('service.alias.transitive', 'base');
    $container->delegator('service.alias.transitive', 'handler.alias.outer');

    expect($container->get('service.alias.transitive'))->toBe('base:outer:first');

    $container->alias('handler.inner.alias', 'handler.inner.second');

    expect($container->get('service.alias.transitive'))->toBe('base:outer:second');
});

test('runtime alias mutation cannot retarget an existing delegator to protected core', function (): void {
    $container = (new ContainerBuilder())->build();

    $container->set('protected.target', 'base');
    $container->alias('protected.alias', 'protected.target');
    $container->delegator(
        'protected.alias',
        static fn(string $entry): string => $entry . ':decorated',
    );

    expect($container->get('protected.alias'))->toBe('base:decorated')
        ->and(fn() => $container->alias('protected.target', ContainerInterface::class))
        ->toThrow(InvalidConfigurationException::class, 'protected DI id');

    $container->set('protected.target', 'next');

    expect($container->get('protected.alias'))->toBe('next:decorated');
});

test('adding an external container invalidates deferred callable ownership', function (): void {
    $container = (new ContainerBuilder())->build();
    $callableId = __NAMESPACE__ . '\\v5DeferredDelegatorNative';

    $container->set('service', 'base');
    $container->delegator('service', $callableId);

    expect($container->get('service'))->toBe('base:native');

    $external = new class ($callableId) implements ContainerInterface {
        public function __construct(private readonly string $id) {}

        public function get(string $id): mixed
        {
            if ($id !== $this->id) {
                throw new RuntimeException($id);
            }

            return static fn(string $entry): string => $entry . ':external';
        }

        public function has(string $id): bool
        {
            return $id === $this->id;
        }
    };

    $container->addContainer($external);

    expect($container->get('service'))->toBe('base:external');
});

test('external takeover invalidates transitive deferred callable dependents', function (): void {
    $container = (new ContainerBuilder())->build();

    $container->set(
        'external.inner',
        static fn(callable $entry): callable =>
            static fn(string $value): string => appendDeferredResult($entry, $value, ':local'),
    );
    $container->set('external.outer', static fn(string $value): string => $value . ':outer');
    $container->delegator('external.outer', 'external.inner');
    $container->set('external.service', 'base');
    $container->delegator('external.service', 'external.outer');

    expect($container->get('external.service'))->toBe('base:outer:local');

    $external = new class () implements ContainerInterface {
        public function get(string $id): mixed
        {
            if ($id !== 'external.inner') {
                throw new RuntimeException($id);
            }

            return static fn(callable $entry): callable =>
                static fn(string $value): string => appendDeferredResult($entry, $value, ':external');
        }

        public function has(string $id): bool
        {
            return $id === 'external.inner';
        }
    };

    $container->addContainer($external);

    expect($container->get('external.service'))->toBe('base:outer:external');
});

test('external containers own shared get while fresh make keeps the local runtime definition', function (): void {
    $external = new class () implements ContainerInterface {
        public int $hasCalls = 0;

        public function get(string $id): mixed
        {
            return new RuntimeDefinitionOwnershipValue('external');
        }

        public function has(string $id): bool
        {
            ++$this->hasCalls;
            return $id === 'runtime.owned';
        }
    };
    $container = (new ContainerBuilder())->build();
    $container->addContainer($external);
    $container->set(
        'runtime.owned',
        Definition::factory(static fn(): RuntimeDefinitionOwnershipValue =>
            new RuntimeDefinitionOwnershipValue('local')),
    );

    $external->hasCalls = 0;

    $has = $container->has('runtime.owned');
    $hasCalls = $external->hasCalls;
    $shared = $container->get('runtime.owned');
    $fresh = $container->make('runtime.owned');
    if (!$shared instanceof RuntimeDefinitionOwnershipValue
        || !$fresh instanceof RuntimeDefinitionOwnershipValue
    ) {
        throw new RuntimeException('The runtime definition resolved to an unexpected type.');
    }

    expect($has)->toBeTrue()
        ->and($hasCalls)->toBe(1)
        ->and($shared->source)->toBe('external')
        ->and($fresh->source)->toBe('local');
});

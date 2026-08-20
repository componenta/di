<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\DI\ContainerBuilder;
use Componenta\DI\Definition\Definition;
use Componenta\DI\Exception\ConcurrentResolutionException;
use Fiber;
use Psr\Container\ContainerInterface;
use RuntimeException;
use WeakReference;

function v5DeferredDelegatorNative(string $entry, ContainerInterface $container): string
{
    return $entry . ':native';
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

    $first = new Fiber(static fn(): object => $container->get('fiber.shared'));
    $second = new Fiber(static fn(): object => $container->get('fiber.shared'));

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

    $fiber = new Fiber(static fn(): object => $container->get('fiber.abandoned'));
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

    expect($container->has('runtime.owned'))->toBeTrue()
        ->and($external->hasCalls)->toBe(1)
        ->and($container->get('runtime.owned')->source)->toBe('external')
        ->and($container->make('runtime.owned')->source)->toBe('local');
});

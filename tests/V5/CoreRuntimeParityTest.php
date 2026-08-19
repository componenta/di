<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\ConcurrentResolutionException;
use Fiber;
use Psr\Container\ContainerInterface;
use RuntimeException;

function v5DeferredDelegatorNative(string $entry, ContainerInterface $container): string
{
    return $entry . ':native';
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

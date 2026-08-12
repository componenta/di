<?php

declare(strict_types=1);

use Componenta\DI\ContainerBuilder;
use Psr\Container\ContainerInterface;

function deferredDelegatorNativeFallback(string $entry, ContainerInterface $container): string
{
    return $entry . ':native';
}

it('invalidates a decorated entry when an alias used by its deferred delegator changes', function () {
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

it('invalidates deferred delegators through a transitive alias change', function () {
    $container = (new ContainerBuilder())->build();

    $container->set('handler.first', static fn(string $entry): string => $entry . ':first');
    $container->set('handler.second', static fn(string $entry): string => $entry . ':second');
    $container->alias('handler.target', 'handler.first');
    $container->alias('handler', 'handler.target');
    $container->set('service', 'base');
    $container->delegator('service', 'handler');

    expect($container->get('service'))->toBe('base:first');

    $container->alias('handler.target', 'handler.second');

    expect($container->get('service'))->toBe('base:second');
});

it('invalidates deferred delegators when their service is replaced', function () {
    $container = (new ContainerBuilder())->build();

    $container->set('handler', static fn(string $entry): string => $entry . ':first');
    $container->set('service', 'base');
    $container->delegator('service', 'handler');

    expect($container->get('service'))->toBe('base:first');

    $container->set('handler', static fn(string $entry): string => $entry . ':second');

    expect($container->get('service'))->toBe('base:second');
});

it('re-resolves deferred delegators when an external container changes callable ownership', function () {
    $container = (new ContainerBuilder())->build();
    $callableId = 'deferredDelegatorNativeFallback';

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

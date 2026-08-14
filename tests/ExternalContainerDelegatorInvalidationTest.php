<?php

declare(strict_types=1);

use Componenta\DI\ContainerBuilder;
use Psr\Container\ContainerInterface;

function externalInvalidationEmptyContainer(): ContainerInterface
{
    return new class () implements ContainerInterface {
        public function get(string $id): mixed
        {
            throw new RuntimeException($id);
        }

        public function has(string $id): bool
        {
            return false;
        }
    };
}

function externalInvalidationContainer(): \Componenta\DI\Container
{
    $container = (new ContainerBuilder())->build();
    $container->set(
        'handler',
        static fn(string $entry): object => (object) ['value' => $entry . ':handled'],
    );
    $container->set('service', 'base');
    $container->delegator('service', 'handler');

    return $container;
}

it('keeps duplicate external container registration side effect free', function (): void {
    $container = externalInvalidationContainer();
    $external = externalInvalidationEmptyContainer();
    $container->addContainer($external);
    $resolved = $container->get('service');

    $container->addContainer($external);

    expect($container->get('service'))->toBe($resolved)
        ->and($resolved->value)->toBe('base:handled');
});

it('does not invalidate an unrelated decorated entry when a new external container is added', function (): void {
    $container = externalInvalidationContainer();
    $resolved = $container->get('service');

    $container->addContainer(externalInvalidationEmptyContainer());

    expect($container->get('service'))->toBe($resolved)
        ->and($resolved->value)->toBe('base:handled');
});

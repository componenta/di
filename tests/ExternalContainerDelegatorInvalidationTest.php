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

it('keeps duplicate external container registration side effect free', function (): void {
    $calls = 0;
    $container = (new ContainerBuilder())->build();
    $external = externalInvalidationEmptyContainer();

    $container->set('handler', function (string $entry) use (&$calls): string {
        ++$calls;
        return $entry . ':handled';
    });
    $container->set('service', 'base');
    $container->delegator('service', 'handler');
    $container->addContainer($external);

    expect($container->get('service'))->toBe('base:handled')
        ->and($calls)->toBe(1);

    $container->addContainer($external);

    expect($container->get('service'))->toBe('base:handled')
        ->and($calls)->toBe(1);
});

it('does not invalidate a deferred callable for an unrelated new external container', function (): void {
    $calls = 0;
    $container = (new ContainerBuilder())->build();

    $container->set('handler', function (string $entry) use (&$calls): string {
        ++$calls;
        return $entry . ':handled';
    });
    $container->set('service', 'base');
    $container->delegator('service', 'handler');

    expect($container->get('service'))->toBe('base:handled')
        ->and($calls)->toBe(1);

    $container->addContainer(externalInvalidationEmptyContainer());

    expect($container->get('service'))->toBe('base:handled')
        ->and($calls)->toBe(1);
});

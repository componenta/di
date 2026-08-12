<?php

declare(strict_types=1);

use Componenta\DI\ContainerBuilder;

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

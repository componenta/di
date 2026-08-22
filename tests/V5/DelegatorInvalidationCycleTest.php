<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\DI\ContainerBuilder;

test('deferred delegator invalidation remains cycle safe', function (): void {
    $container = (new ContainerBuilder())->build();

    $container->set('cycle.a', static fn(string $value): string => $value . ':a');
    $container->set('cycle.b', static fn(string $value): string => $value . ':b');

    $container->delegator('cycle.a', 'cycle.b');
    $container->delegator('cycle.b', 'cycle.a');

    $container->set('cycle.a', static fn(string $value): string => $value . ':next');

    expect($container->has('cycle.a'))->toBeTrue()
        ->and($container->has('cycle.b'))->toBeTrue();
});

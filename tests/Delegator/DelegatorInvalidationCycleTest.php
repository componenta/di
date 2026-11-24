<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Delegator\DelegatorInvalidationCycle;

use Componenta\DI\Tests\Support\ContainerBuilder;

test('deferred delegator invalidation remains cycle safe', function (): void {
    $container = (new ContainerBuilder())->build();

    $container->set('cycle.a', static fn(string $value): string => $value . ':a');
    $container->set('cycle.b', static fn(string $value): string => $value . ':b');

    $container->delegator('cycle.a', 'cycle.b');
    $container->delegator('cycle.b', 'cycle.a');

    // External ownership bypasses one local pipeline without removing the
    // cyclic registration graph. The warmed consumer must still be invalidated.
    $external = (new ContainerBuilder())
        ->addService('cycle.b', static fn(mixed $entry): mixed => $entry)
        ->build();
    $container->addContainer($external);
    $container->set('consumer', 'base');
    $container->delegator('consumer', 'cycle.a');
    expect($container->get('consumer'))->toBe('base:a');

    $container->set('cycle.a', static fn(string $value): string => $value . ':next');

    expect($container->has('cycle.a'))->toBeTrue()
        ->and($container->has('cycle.b'))->toBeTrue()
        ->and($container->get('consumer'))->toBe('base:next');
});

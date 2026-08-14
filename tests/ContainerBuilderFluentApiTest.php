<?php

declare(strict_types=1);

namespace Componenta\DI\Tests;

use Componenta\DI\ContainerBuilder;

final class BuilderBulkInvokable {}

it('applies the public bulk registration methods to the built container', function (): void {
    $container = (new ContainerBuilder())
        ->addFactories([
            'builder.bulk.factory' => static fn() => (object) ['source' => 'factory'],
        ])
        ->addInvokables([
            'builder.bulk.invokable' => BuilderBulkInvokable::class,
        ])
        ->addAliases([
            'builder.bulk.factory.alias' => 'builder.bulk.factory',
        ])
        ->addServices([
            'builder.bulk.decorated' => 'base',
        ])
        ->addDelegators([
            'builder.bulk.decorated' => [
                static fn(string $value): string => $value . '-first',
                static fn(string $value): string => $value . '-second',
            ],
        ])
        ->build();

    expect($container->get('builder.bulk.factory.alias')->source)->toBe('factory')
        ->and($container->make('builder.bulk.invokable'))
        ->toBeInstanceOf(BuilderBulkInvokable::class)
        ->and($container->get('builder.bulk.decorated'))->toBe('base-first-second');
});

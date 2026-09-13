<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use RuntimeException;

use function Componenta\DI\Tests\Support\container;

final class DelegatorAutoloadFailure extends RuntimeException {}

test('failed delegator registration leaves no partial pipeline entry', function (): void {
    $container = container(['services' => ['decorated' => 'base']]);
    $missing = __NAMESPACE__ . '\\RejectedAutoloadDecorator';
    $autoload = static function (string $class) use ($missing): void {
        if ($class === $missing) {
            throw new DelegatorAutoloadFailure('Decorator autoload failed.');
        }
    };
    spl_autoload_register($autoload, true, true);
    try {
        expect(fn() => $container->delegator('decorated', $missing . '::decorate'))
            ->toThrow(DelegatorAutoloadFailure::class, 'Decorator autoload failed.');
    } finally {
        spl_autoload_unregister($autoload);
    }

    $container->delegator('decorated', static fn(string $entry): string => $entry . ':accepted');

    expect($container->get('decorated'))->toBe('base:accepted');
});

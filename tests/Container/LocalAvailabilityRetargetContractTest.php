<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Container\LocalAvailabilityRetargetContract;

use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Tests\Support\ContainerBuilder;

final class Product {}

test('has reports the current local alias when its previous target autoloads', function (bool $wasAvailable, bool $isAlias): void {
    $container = (new ContainerBuilder())->build();
    $legacy = __NAMESPACE__ . '\\Legacy_' . bin2hex(random_bytes(5));
    $requested = $isAlias ? 'preferred.product' : $legacy;
    $current = new Product();
    if (!$wasAvailable) {
        $container->set('product.current', $current);
    }
    if ($isAlias) {
        $container->alias($requested, $legacy);
    }
    $calls = 0;
    $loader = static function (string $name) use ($legacy, $container, $requested, $wasAvailable, &$calls): void {
        if ($name === $legacy) {
            ++$calls;
            $container->alias($requested, 'product.current');
            if ($wasAvailable) {
                class_alias(Product::class, $legacy, false);
            }
        }
    };
    spl_autoload_register($loader);

    try {
        expect($container->has($requested))->toBe(!$wasAvailable);
        if ($wasAvailable) {
            expect(fn() => $container->get($requested))->toThrow(NotFoundException::class, $requested);
        } else {
            expect($container->get($requested))->toBe($current);
        }
        expect($calls)->toBe(1);
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['previously unavailable' => false, 'previously available' => true])
    ->with(['changed the alias edge' => true, 'converted the canonical ID into an alias' => false]);

test('has follows several retargets in the order their fallback is inspected', function (): void {
    $container = (new ContainerBuilder())->build();
    $first = __NAMESPACE__ . '\\First_' . bin2hex(random_bytes(5));
    $second = __NAMESPACE__ . '\\Second_' . bin2hex(random_bytes(5));
    $container->alias('preferred.product', $first);
    $container->set('product.current', null);
    $checks = [];
    $loader = static function (string $name) use ($container, $first, $second, &$checks): void {
        if ($name === $first) {
            $checks[] = 'first';
            $container->alias('preferred.product', $second);
        } elseif ($name === $second) {
            $checks[] = 'second';
            $container->alias('preferred.product', 'product.current');
        }
    };
    spl_autoload_register($loader);

    try {
        expect($container->has('preferred.product'))->toBeTrue()
            ->and($checks)->toBe(['first', 'second'])
            ->and($container->get('preferred.product'))->toBeNull();
    } finally {
        spl_autoload_unregister($loader);
    }
});

test('has does not replay a fallback for an unrelated or same-canonical alias update', function (bool $sameCanonical): void {
    $container = (new ContainerBuilder())->build();
    $legacy = __NAMESPACE__ . '\\Legacy_' . bin2hex(random_bytes(5));
    $container->alias('preferred.product', $legacy);
    $container->alias('alias.alternate', $legacy);
    $checks = 0;
    $loader = static function (string $name) use ($container, $legacy, $sameCanonical, &$checks): void {
        if ($name === $legacy) {
            ++$checks;
            $container->alias($sameCanonical ? 'preferred.product' : 'unrelated', 'alias.alternate');
        }
    };
    spl_autoload_register($loader);

    try {
        expect($container->has('preferred.product'))->toBeFalse()
            ->and($checks)->toBe(1);
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['same terminal ID' => true, 'unrelated ID' => false]);

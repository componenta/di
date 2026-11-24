<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\External\AvailabilityExternalSuffixContract;

use Closure;
use Componenta\DI\Definition\Definition;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Psr\Container\ContainerInterface;

final class Product {}

final readonly class Provider implements ContainerInterface
{
    /** @param Closure():bool $owns */
    public function __construct(private Closure $owns) {}

    public function has(string $id): bool
    {
        return $id === 'preferred.product' && ($this->owns)();
    }

    public function get(string $id): null
    {
        if (!$this->has($id)) {
            throw NotFoundException::forService($id);
        }
        return null;
    }
}

test('has inspects providers appended during its local miss in registration order', function (bool $earlier, bool $owns): void {
    $di = new ContainerBuilder()->build();
    $legacy = __NAMESPACE__ . '\\Legacy_' . bin2hex(random_bytes(5));
    $di->alias('preferred.product', $legacy);
    $checks = [];
    if ($earlier) {
        $di->addContainer(new Provider(static function () use (&$checks): bool {
            $checks[] = 'earlier';
            return false;
        }));
    }
    $new = new Provider(static function () use (&$checks, $owns): bool {
        $checks[] = 'new';
        return $owns;
    });
    $loader = static function (string $name) use ($di, $legacy, $new, &$checks): void {
        if ($name === $legacy) {
            $checks[] = 'local';
            $di->addContainer($new);
        }
    };
    spl_autoload_register($loader);

    try {
        expect($di->has('preferred.product'))->toBe($owns)
            ->and($checks)->toBe($earlier ? ['earlier', 'local', 'new'] : ['local', 'new']);
        if ($owns) {
            expect($di->get('preferred.product'))->toBeNull();
        }
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['first provider' => false, 'an earlier miss' => true])
    ->with(['owns requested entry' => true, 'misses requested entry' => false]);

test('an appended provider can query the same ID without reentering its active ownership callback', function (): void {
    $di = new ContainerBuilder()->build();
    $legacy = __NAMESPACE__ . '\\Legacy_' . bin2hex(random_bytes(5));
    $di->alias('preferred.product', $legacy);
    $queries = 0;
    $nestedAvailability = null;
    $new = new Provider(static function () use ($di, &$queries, &$nestedAvailability): bool {
        if (++$queries > 1) {
            throw new \LogicException('The active ownership callback was entered recursively.');
        }
        $nestedAvailability = $di->has('preferred.product');
        return true;
    });
    $loader = static function (string $name) use ($di, $legacy, $new): void {
        if ($name === $legacy) {
            $di->addContainer($new);
        }
    };
    spl_autoload_register($loader);

    try {
        expect($di->has('preferred.product'))->toBeTrue()
            ->and($nestedAvailability)->toBeFalse()
            ->and($queries)->toBe(1);
    } finally {
        spl_autoload_unregister($loader);
    }
});

test('an appended miss can make the local entry available without replaying its old fallback', function (string $kind): void {
    $di = new ContainerBuilder()->build();
    $legacy = __NAMESPACE__ . '\\Legacy_' . bin2hex(random_bytes(5));
    $di->alias('preferred.product', $legacy);
    $calls = 0;
    $new = new Provider(static function () use ($di, $kind, $legacy): bool {
        match ($kind) {
            'definition' => $di->set('preferred.product', Definition::factory(static fn(): Product => new Product())),
            'shared' => $di->set('preferred.product', new Product()),
            'retarget' => $di->alias('preferred.product', 'product.current'),
            'declared' => class_alias(Product::class, $legacy, false),
            default => throw new \LogicException('Unknown local entry fixture.'),
        };
        return false;
    });
    $di->set('product.current', new Product());
    $loader = static function (string $name) use ($di, $legacy, $new, &$calls): void {
        if ($name === $legacy) {
            ++$calls;
            $di->addContainer($new);
        }
    };
    spl_autoload_register($loader);

    try {
        expect($di->has('preferred.product'))->toBeTrue()->and($calls)->toBe(1);
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['definition', 'shared', 'retarget', 'declared']);

test('a local hit does not need an appended provider to prove availability', function (): void {
    $di = new ContainerBuilder()->build();
    $legacy = __NAMESPACE__ . '\\Legacy_' . bin2hex(random_bytes(5));
    $di->alias('preferred.product', $legacy);
    $new = new Provider(static fn(): never => throw new \LogicException('Unnecessary ownership check'));
    $loader = static function (string $name) use ($di, $legacy, $new): void {
        if ($name === $legacy) {
            $di->addContainer($new);
            class_alias(Product::class, $legacy, false);
        }
    };
    spl_autoload_register($loader);

    try {
        expect($di->has('preferred.product'))->toBeTrue();
    } finally {
        spl_autoload_unregister($loader);
    }
});

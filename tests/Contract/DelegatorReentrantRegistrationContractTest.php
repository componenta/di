<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\DelegatorReentrantRegistration;

use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Psr\Container\ContainerInterface;

final readonly class Product
{
    public function __construct(public string $value) {}
}

final class Decorator
{
    public static int $calls = 0;

    public static function decorate(object $entry): Product
    {
        ++self::$calls;
        return new Product($entry instanceof Product ? $entry->value . ':decorated' : 'core-decorated');
    }
}

test('delegator registration rejects a target retargeted to protected core during autoload', function (bool $ancestor): void {
    Decorator::$calls = 0;
    $source = new Product('source');
    $container = (new ContainerBuilder())->addService('source', $source)->build();
    $container->alias('parent', 'source');
    $container->alias('public', 'parent');
    expect($container->get('public'))->toBe($source);

    $class = __NAMESPACE__ . '\\ReentrantDecorator_' . bin2hex(random_bytes(5));
    $alias = $ancestor ? 'parent' : 'public';
    $loader = static function (string $requested) use ($class, $container, $alias): void {
        if ($requested === $class) {
            $container->alias($alias, ContainerInterface::class);
            class_alias(Decorator::class, $class);
        }
    };
    spl_autoload_register($loader);

    try {
        expect(fn() => $container->delegator('public', $class . '::decorate'))
            ->toThrow(InvalidConfigurationException::class, 'Cannot decorate protected DI id "public"');

        expect($container->get('public'))->toBe($container)
            ->and($container->get(ContainerInterface::class))->toBe($container)
            ->and($container->get('source'))->toBe($source)
            ->and(Decorator::$calls)->toBe(0);

        $container->alias($alias, 'source');
        expect($container->get('public'))->toBe($source);
        $container->delegator('public', $class . '::decorate');

        expect($container->get('public'))->toEqual(new Product('source:decorated'))
            ->and(Decorator::$calls)->toBe(1);
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['requested alias' => false, 'ancestor alias' => true]);

test('delegator registration accepts an ordinary target selected during autoload', function (): void {
    Decorator::$calls = 0;
    $old = new Product('old');
    $current = new Product('current');
    $container = (new ContainerBuilder())->addServices(['old' => $old, 'current' => $current])->build();
    $container->alias('public', 'old');
    $container->alias('sibling', 'old');
    expect($container->get('public'))->toBe($old)
        ->and($container->get('sibling'))->toBe($old);
    $class = __NAMESPACE__ . '\\AllowedDecorator_' . bin2hex(random_bytes(5));
    $loader = static function (string $requested) use ($class, $container): void {
        if ($requested === $class) {
            $container->alias('public', 'current');
            class_alias(Decorator::class, $class);
        }
    };
    spl_autoload_register($loader);

    try {
        $container->delegator('public', $class . '::decorate');
        $decorated = $container->get('public');

        expect($decorated)->toEqual(new Product('current:decorated'))
            ->and($container->get('public'))->toBe($decorated)
            ->and($container->get('old'))->toBe($old)
            ->and($container->get('sibling'))->toBe($old)
            ->and($container->get('current'))->toBe($current)
            ->and(Decorator::$calls)->toBe(1);
    } finally {
        spl_autoload_unregister($loader);
    }
});

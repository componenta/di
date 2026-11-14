<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\FactoryClassAvailability;

use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Tests\Support\ContainerBuilder;

final readonly class Product
{
    public function __construct(public string $value = 'current') {}
}

test('class definitions accept a class loaded by the interface fallback probe', function (bool $runtime): void {
    $previous = new Product('previous');
    $container = $runtime ? (new ContainerBuilder())->addService('product', $previous)->build() : null;
    if ($container !== null) {
        expect($container->get('product'))->toBe($previous);
    }
    /** @var class-string<Product> $class */
    $class = __NAMESPACE__ . '\\FallbackProduct_' . bin2hex(random_bytes(5));
    $attempts = 0;
    $loader = static function (string $requested) use ($class, &$attempts): void {
        if ($requested === $class && ++$attempts === 2) {
            class_alias(Product::class, $class);
        }
    };
    spl_autoload_register($loader);

    try {
        $definition = ClassDefinition::create($class);
        if ($container === null) {
            $container = (new ContainerBuilder())->addDefinition('product', $definition)->build();
        } else {
            $container->set('product', $definition);
        }

        $shared = $container->get('product');
        $fresh = $container->make('product');
        expect($attempts)->toBe(2)
            ->and($container->has('product'))->toBeTrue()
            ->and($shared)->toEqual(new Product())
            ->and($shared)->not->toBe($previous)
            ->and($container->get('product'))->toBe($shared)
            ->and($fresh)->toEqual(new Product())
            ->and($fresh)->not->toBe($shared);
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['runtime registration' => true, 'declarative registration' => false]);

final class IncompatibleStaticFactory
{
    public static function create(mixed $first, mixed $second, mixed $third): object
    {
        throw new \LogicException('An incompatible factory must not execute.');
    }
}

test('factory admission validates a static callable discovered by its later availability probe', function (): void {
    $class = __NAMESPACE__ . '\\LaterFactory_' . bin2hex(random_bytes(5));
    $attempts = 0;
    $loader = static function (string $requested) use ($class, &$attempts): void {
        if ($requested === $class && ++$attempts === 2) {
            class_alias(IncompatibleStaticFactory::class, $class);
        }
    };
    spl_autoload_register($loader);

    try {
        expect(fn() => (new ContainerBuilder())->addFactory('product', [$class, 'create'])->build())
            ->toThrow(InvalidConfigurationException::class, 'requires 3 arguments');
    } finally {
        spl_autoload_unregister($loader);
    }
});

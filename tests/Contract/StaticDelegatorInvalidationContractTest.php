<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\StaticDelegatorInvalidation;

use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;

final readonly class Product
{
    public function __construct(public string $value) {}
}

final class StaticDecorator
{
    public static int $calls = 0;

    public static function declared(Product $entry): Product
    {
        ++self::$calls;
        return new Product($entry->value . ':declared');
    }

    protected function hiddenInstance(): never
    {
        throw new LogicException('The inaccessible instance method must not be invoked.');
    }

    protected static function hiddenStatic(): never
    {
        throw new LogicException('The inaccessible static method must not be invoked.');
    }

    /** @param array<array-key, mixed> $arguments */
    public static function __callStatic(string $method, array $arguments): Product
    {
        $entry = $arguments[0] ?? null;
        if (!$entry instanceof Product) {
            throw new LogicException('Expected a product in the native delegator.');
        }
        ++self::$calls;
        return new Product($entry->value . ':' . $method);
    }
}

test('native static delegators preserve shared results when an unused owner binding changes', function (string $method): void {
    StaticDecorator::$calls = 0;
    $container = (new ContainerBuilder())->addService('product', new Product('base'))->build();
    $specification = StaticDecorator::class . '::' . $method;
    $container->delegator('product', $specification);
    $original = $container->get('product');

    $container->set(StaticDecorator::class, new \stdClass());

    expect($container->get('product'))->toBe($original)
        ->and(StaticDecorator::$calls)->toBe(1);

    $container->delegator(StaticDecorator::class, static fn(object $entry): object => $entry);
    expect($container->get('product'))->toBe($original)
        ->and(StaticDecorator::$calls)->toBe(1);

    $container->set($specification, static fn(Product $entry): Product => new Product($entry->value . ':replacement'));
    $replacement = $container->get('product');

    expect($replacement)->not->toBe($original)
        ->and($replacement)->toEqual(new Product('base:replacement'))
        ->and($container->get('product'))->toBe($replacement)
        ->and(StaticDecorator::$calls)->toBe(1);
})->with(['declared', 'dynamic', 'hiddenInstance', 'hiddenStatic']);

test('static delegators loaded during registration do not acquire owner dependencies', function (bool $loaded): void {
    StaticDecorator::$calls = 0;
    $class = __NAMESPACE__ . '\\AutoloadedDecorator_' . bin2hex(random_bytes(5));
    if ($loaded) {
        class_alias(StaticDecorator::class, $class);
    }
    $attempts = 0;
    $loader = static function (string $requested) use ($class, &$attempts): void {
        if ($requested === $class && ++$attempts === 2) {
            class_alias(StaticDecorator::class, $class);
        }
    };
    spl_autoload_register($loader);

    try {
        $container = (new ContainerBuilder())->addService('product', new Product('base'))->build();
        $container->delegator('product', $class . '::declared');
        expect(class_exists($class, false))->toBeTrue();
        $original = $container->get('product');

        $container->set($class, new \stdClass());

        expect($container->get('product'))->toBe($original)
            ->and(StaticDecorator::$calls)->toBe(1);
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['already loaded' => true, 'loaded on second probe' => false]);

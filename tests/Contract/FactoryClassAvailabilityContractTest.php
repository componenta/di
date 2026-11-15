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

#[\Componenta\DI\Attribute\NoConstructor]
interface IneligibleInterface {}

#[\Componenta\DI\Attribute\NoConstructor]
trait IneligibleTrait {}

final class EligibleTraitConsumer
{
    use IneligibleTrait;
}

#[\Componenta\DI\Attribute\NoConstructor]
abstract class IneligibleAbstract {}

#[\Componenta\DI\Attribute\NoConstructor]
enum IneligibleEnum
{
    case Value;
}

/** @param class-string $class */
test('implicit entries reject ineligible class kinds even when constructor policy would permit allocation', function (string $class): void {
    /** @var class-string $class */
    $container = (new ContainerBuilder())->build();
    $pipeline = $container->get(\Componenta\DI\Object\ObjectPipeline::class);

    expect($pipeline->canCreate(new \ReflectionClass($class)))->toBeFalse()
        ->and($container->has($class))->toBeFalse()
        ->and(fn() => $container->get($class))->toThrow(\Componenta\DI\Exception\NotFoundException::class)
        ->and(fn() => $container->make($class))->toThrow(\Componenta\DI\Exception\NotFoundException::class);
})->with([
    'anonymous class' => [(new class () {})::class],
    'interface with constructor policy' => [IneligibleInterface::class],
    'trait with constructor policy' => [IneligibleTrait::class],
    'abstract class with constructor policy' => [IneligibleAbstract::class],
    'enum with constructor policy' => [IneligibleEnum::class],
]);

test('implicit entries can create concrete consumers of traits', function (): void {
    $container = (new ContainerBuilder())->build();
    $pipeline = $container->get(\Componenta\DI\Object\ObjectPipeline::class);

    expect($pipeline->canCreate(new \ReflectionClass(EligibleTraitConsumer::class)))->toBeTrue()
        ->and($container->has(EligibleTraitConsumer::class))->toBeTrue();

    $shared = $container->get(EligibleTraitConsumer::class);
    $fresh = $container->make(EligibleTraitConsumer::class);

    expect($shared)->toBeInstanceOf(EligibleTraitConsumer::class)
        ->and($fresh)->toBeInstanceOf(EligibleTraitConsumer::class)
        ->and($fresh)->not->toBe($shared);
});

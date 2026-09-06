<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Attribute\Make;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Container;
use Componenta\DI\Resolver\Attribute\Handler\MakeHandler;
use Componenta\DI\Tests\Support\ContainerBuilder;
use ReflectionClass;

final class SelectedMakeProduct
{
    public static int $constructions = 0;
    public string $value;

    public function __construct()
    {
        ++self::$constructions;
        $this->value = 'ready';
    }
}

final class SelectedMakeProperty
{
    #[Make, Proxy]
    public SelectedMakeProduct $value;
}

function selectedMakeParameter(#[Make, Proxy] SelectedMakeProduct $value): SelectedMakeProduct
{
    return $value;
}

it('does not activate an unregistered Proxy beside Make', function (bool $property): void {
    SelectedMakeProduct::$constructions = 0;
    $container = (new ContainerBuilder())
        ->replaceAttributeDefinitions()
        ->addAttributeDefinition(static fn(Container $container): AttributeDefinition => new AttributeDefinition(
            Make::class,
            $container->get(MakeHandler::class),
            [ValueProvider::class],
        ))
        ->build();

    $product = $property
        ? $container->make(SelectedMakeProperty::class)->value
        : $container->call(__NAMESPACE__ . '\selectedMakeParameter');

    if (!$product instanceof SelectedMakeProduct) {
        throw new \LogicException('Expected the declared product type.');
    }

    expect(new ReflectionClass(SelectedMakeProduct::class)->isUninitializedLazyObject($product))->toBeFalse()
        ->and(SelectedMakeProduct::$constructions)->toBe(1);
})->with(['property' => true, 'parameter' => false]);

final class UnusedMakeArgument
{
    public static int $constructions = 0;

    public function __construct()
    {
        ++self::$constructions;
    }
}

final class SelectedProxyProperty
{
    #[Make('unregistered.make.entry', ['argument' => new UnusedMakeArgument()]), Proxy]
    public SelectedMakeProduct $value;
}

function selectedProxyParameter(
    #[Make('unregistered.make.entry', ['argument' => new UnusedMakeArgument()]), Proxy]
    SelectedMakeProduct $value,
): SelectedMakeProduct {
    return $value;
}

it('does not read an unregistered Make or evaluate its arguments beside Proxy', function (bool $property): void {
    SelectedMakeProduct::$constructions = 0;
    UnusedMakeArgument::$constructions = 0;
    $container = (new ContainerBuilder())
        ->replaceAttributeDefinitions()
        ->addAttributeDefinition(static fn(Container $container): AttributeDefinition => new AttributeDefinition(
            Proxy::class,
            $container->get(MakeHandler::class),
        ))
        ->build();

    $product = $property
        ? $container->make(SelectedProxyProperty::class)->value
        : $container->call(__NAMESPACE__ . '\selectedProxyParameter');

    if (!$product instanceof SelectedMakeProduct) {
        throw new \LogicException('Expected the declared product type.');
    }

    expect(new ReflectionClass(SelectedMakeProduct::class)->isUninitializedLazyObject($product))->toBeTrue()
        ->and(SelectedMakeProduct::$constructions)->toBe(0)
        ->and(UnusedMakeArgument::$constructions)->toBe(0)
        ->and($product->value)->toBe('ready')
        ->and(SelectedMakeProduct::$constructions)->toBe(1);
})->with(['property' => true, 'parameter' => false]);

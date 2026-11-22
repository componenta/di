<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\TypedEntry;

use Componenta\DI\Container;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Tests\Support\ContainerBuilder;
use ReflectionMethod;

final class LegacyId {}

final readonly class Product
{
    public function __construct(public string $label = 'default') {}
}

test('entry resolution exposes string identifiers and its declared result types', function (): void {
    $get = new ReflectionMethod(Container::class, 'get');
    $make = new ReflectionMethod(Container::class, 'make');

    expect((string) $get->getParameters()[0]->getType())->toBe('string')
        ->and((string) $get->getReturnType())->toBe('mixed')
        ->and((string) $make->getParameters()[0]->getType())->toBe('string')
        ->and((string) $make->getReturnType())->toBe('object');
});

test('get returns registered scalar and null values for string ids', function (): void {
    $di = (new ContainerBuilder())->addServices(['feature.enabled' => false, 'optional.value' => null])->build();

    expect($di->get('feature.enabled'))->toBeFalse()
        ->and($di->get('optional.value'))->toBeNull();
});

test('class-name ids can resolve objects of a different registered class', function (): void {
    $di = (new ContainerBuilder())->addDefinition(LegacyId::class, new ClassDefinition(Product::class))->build();
    $value = $di->get(LegacyId::class);

    expect($value)->toBeInstanceOf(Product::class)
        ->and($di->get(LegacyId::class))->toBe($value)
        ->and($di->make(LegacyId::class, ['label' => 'custom']))->toEqual(new Product('custom'));
});

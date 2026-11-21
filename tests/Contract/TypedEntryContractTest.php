<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\TypedEntry;

use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Tests\Support\ContainerBuilder;

final class LegacyId {}

final readonly class Product
{
    public function __construct(public string $label = 'default') {}
}

test('typed lookup validates an independent expected type while ids stay opaque', function (): void {
    $di = (new ContainerBuilder())->addDefinition(LegacyId::class, new ClassDefinition(Product::class))->build();
    $value = $di->get(LegacyId::class);

    expect($value)->toBeInstanceOf(Product::class)
        ->and($di->getAs(LegacyId::class, Product::class))->toBe($value)
        ->and($di->makeAs(LegacyId::class, Product::class, ['label' => 'custom']))->toEqual(new Product('custom'))
        ->and(fn() => $di->getAs(LegacyId::class, LegacyId::class))->toThrow(ResolutionException::class, Product::class)
        ->and(fn() => $di->makeAs(LegacyId::class, LegacyId::class))->toThrow(ResolutionException::class, Product::class);
});

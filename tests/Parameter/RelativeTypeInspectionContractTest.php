<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Parameter\RelativeTypeInspectionContract;

use Componenta\DI\Resolver\TypeHints;
use Componenta\DI\Tests\Support\ContainerBuilder;
use ReflectionClass;
use ReflectionProperty;

class RelativeTypeParent {}

final class RelativeTypeChild extends RelativeTypeParent
{
    public self $same;
    public parent $ancestor;
}

interface IntersectionLeft {}
interface IntersectionRight {}
final class IntersectionValue implements IntersectionLeft, IntersectionRight {}

test('type inspection recognizes canonical relative class types with and without context', function (): void {
    $self = new ReflectionProperty(RelativeTypeChild::class, 'same')->getType();
    $parent = new ReflectionProperty(RelativeTypeChild::class, 'ancestor')->getType();
    $childContext = contextualTypeReflection(RelativeTypeChild::class);
    $rootContext = contextualTypeReflection(RelativeTypeParent::class);

    expect(TypeHints::classOf($self))->toBe(RelativeTypeChild::class)
        ->and(TypeHints::classOf($parent))->toBe(RelativeTypeParent::class)
        ->and(TypeHints::classOf($self, $childContext))->toBe(RelativeTypeChild::class)
        ->and(TypeHints::classOf($parent, $childContext))->toBe(RelativeTypeParent::class)
        ->and(TypeHints::classOf($parent, $rootContext))->toBe(RelativeTypeParent::class)
        ->and(TypeHints::matches($self, new RelativeTypeChild()))->toBeTrue()
        ->and(TypeHints::matches($self, new RelativeTypeChild(), $childContext))->toBeTrue()
        ->and(TypeHints::matches($parent, new RelativeTypeParent(), $childContext))->toBeTrue();
});

test('intersection parameters accept type-keyed objects only when every component matches', function (): void {
    $container = (new ContainerBuilder())->build();
    $value = new IntersectionValue();
    $partial = new class () implements IntersectionLeft {};
    $callback = static fn(IntersectionLeft&IntersectionRight $value): object => $value;

    expect($container->call($callback, [IntersectionLeft::class => $value]))->toBe($value)
        ->and($container->call($callback, [IntersectionRight::class => $value]))->toBe($value)
        ->and($container->call($callback, [
            IntersectionLeft::class => $partial,
            IntersectionRight::class => $value,
        ]))->toBe($value);
});

/**
 * @param class-string $class
 * @return ReflectionClass<object>
 */
function contextualTypeReflection(string $class): ReflectionClass
{
    return new ReflectionClass($class);
}

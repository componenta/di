<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

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

test('type inspection supports relative PHP 8.4 types and canonical PHP 8.5 types', function (): void {
    $self = new ReflectionProperty(RelativeTypeChild::class, 'same')->getType();
    $parent = new ReflectionProperty(RelativeTypeChild::class, 'ancestor')->getType();
    $childContext = contextualTypeReflection(RelativeTypeChild::class);
    $rootContext = contextualTypeReflection(RelativeTypeParent::class);

    $canonical = PHP_VERSION_ID >= 80500;
    expect(TypeHints::classOf($self))->toBe($canonical ? RelativeTypeChild::class : null)
        ->and(TypeHints::classOf($parent))->toBe($canonical ? RelativeTypeParent::class : null)
        ->and(TypeHints::classOf($self, $childContext))->toBe(RelativeTypeChild::class)
        ->and(TypeHints::classOf($parent, $childContext))->toBe(RelativeTypeParent::class)
        ->and(TypeHints::classOf($parent, $rootContext))->toBe($canonical ? RelativeTypeParent::class : null)
        ->and(TypeHints::matches($self, new RelativeTypeChild()))->toBe($canonical)
        ->and(TypeHints::matches($self, new RelativeTypeChild(), $childContext))->toBeTrue()
        ->and(TypeHints::matches($parent, new RelativeTypeParent(), $childContext))->toBeTrue();
});

test('intersection parameters accept type-keyed objects only when every component matches', function (): void {
    $container = (new ContainerBuilder())->build();
    $value = new IntersectionValue();
    $callback = static fn(IntersectionLeft&IntersectionRight $value): object => $value;

    expect($container->call($callback, [IntersectionLeft::class => $value]))->toBe($value)
        ->and($container->call($callback, [IntersectionRight::class => $value]))->toBe($value);
});

/**
 * @param class-string $class
 * @return ReflectionClass<object>
 */
function contextualTypeReflection(string $class): ReflectionClass
{
    return new ReflectionClass($class);
}

<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\Attribute\Init;
use Componenta\DI\Attribute\Inject;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use ReflectionClass;

test('Inject and Init are declared for properties only', function (): void {
    $inject = (new ReflectionClass(Inject::class))->getAttributes(Attribute::class)[0]->newInstance();
    $init = (new ReflectionClass(Init::class))->getAttributes(Attribute::class)[0]->newInstance();

    expect($inject->flags)->toBe(Attribute::TARGET_PROPERTY)
        ->and($init->flags)->toBe(Attribute::TARGET_PROPERTY);
});

test('property-only value attributes are rejected on constructor parameters', function (): void {
    $container = (new ContainerBuilder())->build();
    $injectTarget = __NAMESPACE__ . '\\InvalidParameterInjectTarget';
    $initTarget = __NAMESPACE__ . '\\InvalidParameterInitTarget';

    if (!class_exists($injectTarget, false)) {
        eval('namespace ' . __NAMESPACE__ . '; final class InvalidParameterInjectTarget { public function __construct(#[\\Componenta\\DI\\Attribute\\Inject] \\stdClass $value) {} }');
        eval('namespace ' . __NAMESPACE__ . '; final class InvalidParameterInitTarget { public function __construct(#[\\Componenta\\DI\\Attribute\\Init("time")] int $value) {} }');
    }

    expect(fn() => $container->make($injectTarget))
        ->toThrow(AttributeCompositionException::class, 'cannot target parameter')
        ->and(fn() => $container->make($initTarget))
        ->toThrow(AttributeCompositionException::class, 'cannot target parameter');
});

test('promotion rejects attributes that allow neither parameters nor properties', function (): void {
    $class = __NAMESPACE__ . '\\InvalidPromotedLazyTarget';
    if (!class_exists($class, false)) {
        eval('namespace ' . __NAMESPACE__ . '; final class InvalidPromotedLazyTarget { public function __construct(#[\\Componenta\\DI\\Attribute\\Lazy] public int $value = 1) {} }');
    }

    expect(fn() => (new ContainerBuilder())->build()->make($class))
        ->toThrow(AttributeCompositionException::class, 'cannot target parameter');
});


#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class PromotedPropertyMetadata {}

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class PromotedParameterMetadata {}

final class PromotedFollowingParameterAttribute
{
    public function __construct(
        #[PromotedPropertyMetadata, ConfigAttribute('value')]
        public string $value = 'constructor',
    ) {}
}

final class PromotedFollowingPropertyAttribute
{
    public function __construct(
        #[PromotedParameterMetadata, Init([self::class, 'initialize'])]
        public string $value = 'constructor',
    ) {}

    public static function initialize(): string
    {
        return 'property';
    }
}

test('promotion keeps valid attributes following a copy for the other target', function (bool $property): void {
    $container = ContainerBuilder::configure(new \Componenta\Config\Config(
        ['value' => 'parameter'],
        new \Componenta\Config\Environment([]),
    ))
        ->addAttributeDefinition(new AttributeDefinition(PromotedPropertyMetadata::class))
        ->addAttributeDefinition(new AttributeDefinition(PromotedParameterMetadata::class))
        ->build();
    $entry = $property
        ? $container->makeAs(PromotedFollowingPropertyAttribute::class, PromotedFollowingPropertyAttribute::class)
        : $container->makeAs(PromotedFollowingParameterAttribute::class, PromotedFollowingParameterAttribute::class);

    expect($entry->value)->toBe($property ? 'property' : 'parameter');
})->with(['parameter target' => false, 'property target' => true]);

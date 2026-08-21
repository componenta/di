<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Composition\Capability\InvocationOnlyValueProvider;
use Componenta\DI\Attribute\QueryParam;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\AttributeCompositionException;
use ReflectionFunction;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class InvocationOnlyFixture {}

final readonly class InvocationOnlyConstructorFixture
{
    public function __construct(
        #[InvocationOnlyFixture]
        public string $value,
    ) {}
}

final readonly class RequestBoundConstructorFixture
{
    public function __construct(
        #[QueryParam('value')]
        public string $value,
    ) {}
}

function invocationOnlyFunctionFixture(#[InvocationOnlyFixture] string $value): string
{
    return $value;
}

function invocationOnlyBuilder(): ContainerBuilder
{
    return (new ContainerBuilder())->addAttributeDefinition(new AttributeDefinition(
        InvocationOnlyFixture::class,
        capabilities: [InvocationOnlyValueProvider::class],
    ));
}

test('custom invocation-only capabilities are valid on callable parameters', function (): void {
    $container = invocationOnlyBuilder()->build();
    $plans = $container->get(AttributePlanBuilder::class);
    $parameter = (new ReflectionFunction(__NAMESPACE__ . '\\invocationOnlyFunctionFixture'))
        ->getParameters()[0];

    expect($plans)->toBeInstanceOf(AttributePlanBuilder::class)
        ->and($plans->build($parameter)->has(InvocationOnlyValueProvider::class))->toBeTrue();
});

test('custom invocation-only capabilities are rejected on constructor parameters', function (): void {
    $container = invocationOnlyBuilder()->build();

    expect(fn() => $container->make(InvocationOnlyConstructorFixture::class))
        ->toThrow(AttributeCompositionException::class, 'is invocation-only and cannot target constructor parameter');
});

test('request-bound attributes are rejected on constructor parameters', function (): void {
    $container = (new ContainerBuilder())->build();

    expect(fn() => $container->make(RequestBoundConstructorFixture::class))
        ->toThrow(AttributeCompositionException::class, 'is invocation-only and cannot target constructor parameter');
});

<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Composition\Capability\InvocationOnlyValueProvider;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use PHPUnit\Framework\Assert;
use ReflectionFunction;

#[Attribute(Attribute::TARGET_ALL)]
final readonly class InvocationOnlyFixture {}

final readonly class InvocationOnlyConstructorFixture
{
    public function __construct(
        #[InvocationOnlyFixture]
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
    [$container, $runtime] = invocationOnlyBuilder()->assemble();
    $plans = $runtime->plans;
    Assert::assertInstanceOf(AttributePlanBuilder::class, $plans);
    $parameter = (new ReflectionFunction(__NAMESPACE__ . '\\invocationOnlyFunctionFixture'))
        ->getParameters()[0];

    expect($plans)->toBeInstanceOf(AttributePlanBuilder::class)
        ->and($plans->build($parameter)->has(InvocationOnlyValueProvider::class))->toBeTrue();
});

#[InvocationOnlyFixture]
final readonly class InvocationOnlyClassFixture {}

final class InvocationOnlyPropertyFixture
{
    #[InvocationOnlyFixture]
    public string $value = 'default';
}

final class InvocationOnlyMethodFixture
{
    #[InvocationOnlyFixture]
    public function marker(): void {}
}

test('custom invocation-only capabilities are rejected outside callable parameters', function (string $class, string $message): void {
    if (!class_exists($class)) {
        throw new \LogicException('Expected an invocation-only target fixture.');
    }
    $container = invocationOnlyBuilder()->build();

    expect(fn() => $container->make($class))
        ->toThrow(AttributeCompositionException::class, $message);
})->with([
    'constructor parameter' => [InvocationOnlyConstructorFixture::class, 'is invocation-only and cannot target constructor parameter'],
    'class' => [InvocationOnlyClassFixture::class, 'is invocation-only and can target callable parameters only'],
    'property' => [InvocationOnlyPropertyFixture::class, 'is invocation-only and can target callable parameters only'],
    'method' => [InvocationOnlyMethodFixture::class, 'is invocation-only and can target callable parameters only'],
]);

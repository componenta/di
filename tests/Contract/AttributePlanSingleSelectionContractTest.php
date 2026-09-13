<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Attribute\Composition\Capability\ValueTransformer;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use ReflectionFunction;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::IS_REPEATABLE)]
final class PlanSelectionTag {}

function planSelectionNone(mixed $value): mixed
{
    return $value;
}
function planSelectionOne(#[PlanSelectionTag] mixed $value): mixed
{
    return $value;
}
function planSelectionTwo(#[PlanSelectionTag, PlanSelectionTag] mixed $value): mixed
{
    return $value;
}

test('a plan exposes absence and one usage without inventing a default selection', function (): void {
    $registry = new AttributeDefinitionRegistry();
    $definition = new AttributeDefinition(PlanSelectionTag::class, capabilities: [ValueTransformer::class]);
    $registry->register($definition);
    $builder = new AttributePlanBuilder($registry);
    $empty = $builder->build((new ReflectionFunction(__NAMESPACE__ . '\\planSelectionNone'))->getParameters()[0]);
    $single = $builder->build((new ReflectionFunction(__NAMESPACE__ . '\\planSelectionOne'))->getParameters()[0]);

    expect($empty->one(ValueTransformer::class))->toBeNull()
        ->and($empty->has(ValueTransformer::class))->toBeFalse()
        ->and($single->one(ValueTransformer::class))->toBe($single->all(ValueTransformer::class)[0])
        ->and($single->one(ValueTransformer::class)?->definition)->toBe($definition)
        ->and($single->one(ValueProvider::class))->toBeNull();
});

test('singular plan selection refuses two usages even when the capability permits repetition', function (): void {
    $registry = new AttributeDefinitionRegistry();
    $registry->register(new AttributeDefinition(PlanSelectionTag::class, capabilities: [ValueTransformer::class]));
    $plan = (new AttributePlanBuilder($registry))->build((new ReflectionFunction(__NAMESPACE__ . '\\planSelectionTwo'))->getParameters()[0]);

    expect($plan->all(ValueTransformer::class))->toHaveCount(2)
        ->and(fn() => $plan->one(ValueTransformer::class))
        ->toThrow(AttributeCompositionException::class, 'Capability "' . ValueTransformer::class . '" is not singular in this plan.');
});

test('extension phases retain their stable serialized identifiers', function (): void {
    expect([
        AttributePhase::BeforeInstantiation->value,
        AttributePhase::AfterInstantiation->value,
        AttributePhase::Both->value,
    ])->toBe([100, 200, 300]);
});

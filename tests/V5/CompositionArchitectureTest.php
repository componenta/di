<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeCapabilityInterface;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use ReflectionMethod;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class RuleA {}

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class RuleB {}

final readonly class RequiresRuleB implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void
    {
        if (!$set->has(RuleB::class)) {
            throw new AttributeCompositionException('RuleA requires RuleB.');
        }
    }
}

final class RuleTarget
{
    public function missing(#[RuleA] string $value): void {}
    public function complete(#[RuleA, RuleB] string $value): void {}
}

interface AttributeFamilyOne {}
interface AttributeFamilyTwo {}

interface AuditDeclaredAttributeFamily {}
interface AuditSelectorParentCapability extends AttributeCapabilityInterface {}
interface AuditSelectorCapability extends AuditSelectorParentCapability {}
interface AuditOtherSelectorCapability extends AttributeCapabilityInterface {}

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class AmbiguousFamilyAttribute implements AttributeFamilyOne, AttributeFamilyTwo {}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class AuditDeclaredUsageAttribute implements AuditDeclaredAttributeFamily
{
    public static int $constructions = 0;

    public function __construct(
        public readonly string $name,
        public readonly int $limit = 1,
    ) {
        ++self::$constructions;
    }
}

final class AuditDeclaredUsageRule implements AttributeCompositionRuleInterface
{
    /** @var array<string, mixed>|null */
    public ?array $observation = null;

    public function validate(AttributeUsage $attribute, AttributeSet $set): void
    {
        $this->observation = [
            'class' => $attribute->attributeClass,
            'arguments' => $attribute->arguments,
            'is_attribute' => $attribute->is(AuditDeclaredUsageAttribute::class),
            'is_family' => $attribute->is(AuditDeclaredAttributeFamily::class),
            'is_other_attribute' => $attribute->is(RuleB::class),
            'has_capability' => $attribute->hasCapability(AuditSelectorCapability::class),
            'has_parent_capability' => $attribute->hasCapability(AuditSelectorParentCapability::class),
            'has_other_capability' => $attribute->hasCapability(AuditOtherSelectorCapability::class),
            'matches_attribute' => $attribute->matches(AuditDeclaredUsageAttribute::class),
            'matches_family' => $attribute->matches(AuditDeclaredAttributeFamily::class),
            'matches_other_attribute' => $attribute->matches(RuleB::class),
            'matches_capability' => $attribute->matches(AuditSelectorCapability::class),
            'matches_parent_capability' => $attribute->matches(AuditSelectorParentCapability::class),
            'matches_other_capability' => $attribute->matches(AuditOtherSelectorCapability::class),
            'set_returns_same_usage' => $set->one(AuditSelectorCapability::class) === $attribute,
            'set_has_other' => $set->has(AuditOtherSelectorCapability::class),
        ];
    }
}

test('custom composition rules see the complete attribute set before execution', function (): void {
    $registry = new AttributeDefinitionRegistry();
    $registry->register(new AttributeDefinition(
        RuleA::class,
        handler: null,
        rules: [new RequiresRuleB()],
    ));
    $registry->register(new AttributeDefinition(RuleB::class));
    $plans = new AttributePlanBuilder($registry);

    $missing = (new ReflectionMethod(RuleTarget::class, 'missing'))->getParameters()[0];
    $complete = (new ReflectionMethod(RuleTarget::class, 'complete'))->getParameters()[0];

    expect(fn() => $plans->build($missing))
        ->toThrow(AttributeCompositionException::class, 'RuleA requires RuleB')
        ->and($plans->build($complete)->usages)->toHaveCount(2);
});

test('declarative requirements reject a missing companion attribute', function (bool $capability): void {
    $selector = $capability ? AuditSelectorCapability::class : RuleB::class;
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(RuleA::class, requires: [$selector]))
        ->addAttributeDefinition(new AttributeDefinition(RuleB::class, capabilities: [AuditSelectorCapability::class]))
        ->build();

    expect(fn() => $container->call(
        static fn(#[RuleA] string $value): string => $value,
        ['value' => 'provided'],
    ))->toThrow(AttributeCompositionException::class, 'requires ' . $selector);
})->with([
    'attribute class' => false,
    'capability' => true,
]);

test('declarative requirements accept a matching companion attribute', function (bool $capability): void {
    $selector = $capability ? AuditSelectorCapability::class : RuleB::class;
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(RuleA::class, requires: [$selector]))
        ->addAttributeDefinition(new AttributeDefinition(RuleB::class, capabilities: [AuditSelectorCapability::class]))
        ->build();

    expect($container->call(
        static fn(#[RuleA, RuleB] string $value): string => $value,
        ['value' => 'provided'],
    ))->toBe('provided');
})->with([
    'attribute class' => false,
    'capability' => true,
]);

test('declarative prohibitions reject a matching companion attribute', function (bool $capability): void {
    $selector = $capability ? AuditSelectorCapability::class : RuleB::class;
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(RuleA::class, forbids: [$selector]))
        ->addAttributeDefinition(new AttributeDefinition(RuleB::class, capabilities: [AuditSelectorCapability::class]))
        ->build();

    expect(fn() => $container->call(
        static fn(#[RuleA, RuleB] string $value): string => $value,
        ['value' => 'provided'],
    ))->toThrow(AttributeCompositionException::class, 'forbids ' . $selector);
})->with([
    'attribute class' => false,
    'capability' => true,
]);

test('declarative prohibitions allow calls without the forbidden companion', function (bool $capability): void {
    $selector = $capability ? AuditSelectorCapability::class : RuleB::class;
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(RuleA::class, forbids: [$selector]))
        ->addAttributeDefinition(new AttributeDefinition(RuleB::class, capabilities: [AuditSelectorCapability::class]))
        ->build();

    expect($container->call(
        static fn(#[RuleA] string $value): string => $value,
        ['value' => 'provided'],
    ))->toBe('provided');
})->with([
    'attribute class' => false,
    'capability' => true,
]);

test('plan memoization invalidates when registry semantics change', function (): void {
    $registry = new AttributeDefinitionRegistry();
    $registry->register(new AttributeDefinition(RuleA::class));
    $plans = new AttributePlanBuilder($registry);
    $parameter = (new ReflectionMethod(RuleTarget::class, 'complete'))->getParameters()[0];

    expect($plans->build($parameter)->usages)->toHaveCount(1);

    $registry->register(new AttributeDefinition(RuleB::class));

    expect($plans->build($parameter)->usages)->toHaveCount(2);
});

test('inherited semantic definitions never resolve by registration order when equally specific', function (): void {
    $registry = new AttributeDefinitionRegistry();
    $registry->register(new AttributeDefinition(AttributeFamilyOne::class));
    $registry->register(new AttributeDefinition(AttributeFamilyTwo::class));

    expect(fn() => $registry->definition(AmbiguousFamilyAttribute::class))
        ->toThrow(InvalidConfigurationException::class, 'multiple equally specific');
});

test('composition rules inspect declared metadata without constructing runtime attributes', function (): void {
    AuditDeclaredUsageAttribute::$constructions = 0;
    $rule = new AuditDeclaredUsageRule();
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(
            AuditDeclaredUsageAttribute::class,
            capabilities: [AuditSelectorCapability::class],
            rules: [$rule],
        ))
        ->build();

    $result = $container->call(
        static fn(
            #[AuditDeclaredUsageAttribute(name: 'declared', limit: 3)] string $value,
        ): string => $value,
        ['value' => 'resolved'],
    );

    expect($result)->toBe('resolved')
        ->and(AuditDeclaredUsageAttribute::$constructions)->toBe(0)
        ->and($rule->observation)->toBe([
            'class' => AuditDeclaredUsageAttribute::class,
            'arguments' => ['name' => 'declared', 'limit' => 3],
            'is_attribute' => true,
            'is_family' => true,
            'is_other_attribute' => false,
            'has_capability' => true,
            'has_parent_capability' => true,
            'has_other_capability' => false,
            'matches_attribute' => true,
            'matches_family' => true,
            'matches_other_attribute' => false,
            'matches_capability' => true,
            'matches_parent_capability' => true,
            'matches_other_capability' => false,
            'set_returns_same_usage' => true,
            'set_has_other' => false,
        ]);
});

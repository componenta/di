<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Attribute\AttributePlanSelectionContract;

use Attribute;
use Componenta\Config\ConfigKey;
use Componenta\DI\Attribute\Composition\AttributeCapabilityInterface;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use PHPUnit\Framework\Assert;
use ReflectionMethod;

interface SelectionCapability extends AttributeCapabilityInterface {}
interface FirstSelectionCapability extends SelectionCapability {}
interface SecondSelectionCapability extends SelectionCapability {}

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::IS_REPEATABLE)]
class SelectionAttribute {}

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::IS_REPEATABLE)]
final class FirstSelectionAttribute extends SelectionAttribute {}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class MiddleSelectionAttribute extends SelectionAttribute {}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class LastSelectionAttribute {}

final class SelectionTarget
{
    public function ordered(
        #[FirstSelectionAttribute, LastSelectionAttribute, MiddleSelectionAttribute, FirstSelectionAttribute]
        string $value,
    ): void {}

    public function repeated(
        #[FirstSelectionAttribute, MiddleSelectionAttribute, FirstSelectionAttribute]
        string $value,
    ): void {}
}

test('capability selection applies declaration order to attributes unlocked by a constraint', function (): void {
    [$container, $runtime] = \Componenta\DI\Tests\Support\assembledContainer([
        ConfigKey::ATTRIBUTE_DEFINITIONS => [
            new AttributeDefinition(
                FirstSelectionAttribute::class,
                capabilities: [FirstSelectionCapability::class, SelectionCapability::class],
            ),
            new AttributeDefinition(
                MiddleSelectionAttribute::class,
                capabilities: [SecondSelectionCapability::class],
            ),
            new AttributeDefinition(
                LastSelectionAttribute::class,
                capabilities: [FirstSelectionCapability::class],
                after: [MiddleSelectionAttribute::class],
            ),
        ],
    ]);
    $target = (new ReflectionMethod(SelectionTarget::class, 'ordered'))->getParameters()[0];
    $plans = $runtime->plans;
    Assert::assertInstanceOf(AttributePlanBuilder::class, $plans);
    $plan = $plans->build($target);

    expect(array_map(
        static fn(AttributeUsage $usage): string => $usage->attributeClass,
        $plan->all(SelectionCapability::class),
    ))->toBe([
        FirstSelectionAttribute::class,
        MiddleSelectionAttribute::class,
        LastSelectionAttribute::class,
        FirstSelectionAttribute::class,
    ])->and($plan->one(SecondSelectionCapability::class))->toBe($plan->usages[1]);
});

test('attribute family selection preserves interleaved declaration order', function (): void {
    [$container, $runtime] = \Componenta\DI\Tests\Support\assembledContainer([
        ConfigKey::ATTRIBUTE_DEFINITIONS => [
            new AttributeDefinition(FirstSelectionAttribute::class),
            new AttributeDefinition(MiddleSelectionAttribute::class),
        ],
    ]);
    $target = (new ReflectionMethod(SelectionTarget::class, 'repeated'))->getParameters()[0];
    $plan = $runtime->plans->build($target);

    expect(array_map(
        static fn(AttributeUsage $usage): string => $usage->attributeClass,
        $plan->attributes(SelectionAttribute::class),
    ))->toBe([
        FirstSelectionAttribute::class,
        MiddleSelectionAttribute::class,
        FirstSelectionAttribute::class,
    ]);
});

test('repeated capability selectors preserve per-target precedence and fresh rule validation', function (): void {
    $rule = new class () implements AttributeCompositionRuleInterface {
        public int $checks = 0;

        public function validate(AttributeUsage $attribute, AttributeSet $set): void
        {
            ++$this->checks;
        }
    };
    $registry = new AttributeDefinitionRegistry();
    $registry->register(new AttributeDefinition(FirstSelectionAttribute::class, after: [SelectionCapability::class]));
    $registry->register(new AttributeDefinition(MiddleSelectionAttribute::class, before: [SelectionCapability::class]));
    $registry->register(new AttributeDefinition(
        LastSelectionAttribute::class,
        capabilities: [SecondSelectionCapability::class],
        after: [SelectionCapability::class],
        rules: [$rule],
    ));
    $plans = new AttributePlanBuilder($registry);
    $ordered = (new ReflectionMethod(SelectionTarget::class, 'ordered'))->getParameters()[0];
    $withoutMatch = (new ReflectionMethod(SelectionTarget::class, 'repeated'))->getParameters()[0];
    $declarationOrder = static fn(AttributePlan $plan): array => array_map(
        static fn(AttributeUsage $usage): int => $usage->declarationOrder,
        $plan->usages,
    );

    expect($declarationOrder($plans->build($ordered)))->toBe([2, 1, 0, 3])
        ->and($declarationOrder($plans->build($withoutMatch)))->toBe([0, 1, 2])
        ->and($declarationOrder($plans->build($ordered)))->toBe([2, 1, 0, 3])
        ->and($rule->checks)->toBe(2);
});

test('semantic plans and attribute sets own their usage lists', function (bool $changeOriginal): void {
    [, $runtime] = \Componenta\DI\Tests\Support\assembledContainer([
        ConfigKey::ATTRIBUTE_DEFINITIONS => [
            new AttributeDefinition(FirstSelectionAttribute::class),
            new AttributeDefinition(MiddleSelectionAttribute::class),
        ],
    ]);
    $target = (new ReflectionMethod(SelectionTarget::class, 'repeated'))->getParameters()[0];
    [$first, $middle] = $runtime->plans->build($target)->usages;
    $slot = $first;
    $usages = [&$slot];
    $plan = new AttributePlan($target, $usages);
    $set = new AttributeSet($usages);

    if ($changeOriginal) {
        $slot = $middle;
    } else {
        $planUsages = $plan->usages;
        $planUsages[0] = $middle;
        expect($set->one(FirstSelectionAttribute::class))->toBe($first);

        $setUsages = $set->attributes;
        $setUsages[0] = $middle;
        expect($slot)->toBe($first);
    }

    expect($plan->attributes(FirstSelectionAttribute::class))->toBe([$first])
        ->and($set->one(FirstSelectionAttribute::class))->toBe($first);
})->with(['caller input' => true, 'public views' => false]);

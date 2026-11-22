<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Attribute;
use Componenta\Config\ConfigKey;
use Componenta\DI\Attribute\Composition\AttributeCapabilityInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
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

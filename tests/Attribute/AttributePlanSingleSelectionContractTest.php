<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Attribute\AttributePlanSingleSelectionContract;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeCapabilityInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Attribute\Composition\Capability\ValueTransformer;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use ReflectionFunction;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::IS_REPEATABLE)]
final class PlanSelectionTag {}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class PlanSelectionOtherTag {}

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

function planSelectionMixed(#[PlanSelectionTag, PlanSelectionOtherTag] mixed $value): mixed
{
    return $value;
}

interface PlanSelectionRootCapability extends AttributeCapabilityInterface {}
interface PlanSelectionDerivedCapability extends PlanSelectionRootCapability {}

final class PlanSelectionNativeArgument
{
    public static int $constructions = 0;

    public function __construct()
    {
        ++self::$constructions;
    }
}

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class PlanSelectionNativeTag
{
    public function __construct(public PlanSelectionNativeArgument $argument) {}
}

function planSelectionNative(
    #[PlanSelectionNativeTag(new PlanSelectionNativeArgument())] mixed $value,
): mixed {
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
        ->and($single->one(ValueProvider::class))->toBeNull()
        ->and($single->has(ValueTransformer::class))->toBeTrue()
        ->and($single->has(ValueProvider::class))->toBeFalse();
});

test('singular plan selection refuses two usages even when the capability permits repetition', function (): void {
    $registry = new AttributeDefinitionRegistry();
    $registry->register(new AttributeDefinition(PlanSelectionTag::class, capabilities: [ValueTransformer::class]));
    $plan = (new AttributePlanBuilder($registry))->build((new ReflectionFunction(__NAMESPACE__ . '\\planSelectionTwo'))->getParameters()[0]);

    expect($plan->all(ValueTransformer::class))->toHaveCount(2)
        ->and(fn() => $plan->one(ValueTransformer::class))
        ->toThrow(AttributeCompositionException::class, 'Capability "' . ValueTransformer::class . '" is not singular in this plan.')
        ->and(fn() => (new AttributeSet($plan->usages))->one(ValueTransformer::class))
        ->toThrow(AttributeCompositionException::class, 'Selector "' . ValueTransformer::class . '" is not singular in this attribute set.');
});

test('set selection accounts for every match when a selector becomes available during the call', function (
    string $query,
    bool $capability,
    string $mode,
): void {
    $registry = new AttributeDefinitionRegistry();
    $registry->register(new AttributeDefinition(PlanSelectionTag::class, capabilities: [PlanSelectionDerivedCapability::class]));
    $registry->register(new AttributeDefinition(PlanSelectionOtherTag::class));
    $function = $mode === 'only prefix' ? 'planSelectionMixed' : 'planSelectionTwo';
    $parameter = new ReflectionFunction(__NAMESPACE__ . '\\' . $function)->getParameters()[0];
    $set = new AttributeSet(new AttributePlanBuilder($registry)->build($parameter)->usages);
    $matches = match ($mode) {
        'absent' => [],
        'only prefix' => [$set->attributes[0]],
        default => $set->attributes,
    };

    /** @var class-string $alias */
    $alias = __NAMESPACE__ . '\\LateSetSelector' . bin2hex(random_bytes(8));
    $source = $capability ? PlanSelectionRootCapability::class : PlanSelectionTag::class;
    if ($mode === 'preloaded') {
        class_alias($source, $alias, false);
    }

    $autoloads = 0;
    $declareAt = match ($mode) {
        'first probe' => 1,
        'second probe', 'only prefix' => 2,
        default => null,
    };
    $loader = static function (string $requested) use ($alias, $source, $declareAt, &$autoloads): void {
        if ($requested === $alias && ++$autoloads === $declareAt) {
            class_alias($source, $alias, false);
        }
    };
    spl_autoload_register($loader, prepend: true);

    try {
        $expectedAutoloads = match ($mode) {
            'preloaded' => 0,
            'first probe' => 1,
            default => 2,
        };
        foreach ([1, 2] as $invocation) {
            if ($query === 'all') {
                expect($set->all($alias))->toBe($matches);
            } elseif ($query === 'has') {
                expect($set->has($alias))->toBe($matches !== []);
            } elseif (count($matches) > 1) {
                expect(fn() => $set->one($alias))->toThrow(
                    AttributeCompositionException::class,
                    'Selector "' . $alias . '" is not singular in this attribute set.',
                );
            } else {
                expect($set->one($alias))->toBe($matches[0] ?? null);
            }
            expect($autoloads)->toBe($mode === 'absent' ? $expectedAutoloads * $invocation : $expectedAutoloads);
        }
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['all', 'one', 'has'])
    ->with(['attribute alias' => false, 'capability alias' => true])
    ->with(['preloaded', 'first probe', 'second probe', 'only prefix', 'absent']);

test('extension phases retain their stable serialized identifiers', function (): void {
    expect([
        AttributePhase::BeforeInstantiation->value,
        AttributePhase::AfterInstantiation->value,
        AttributePhase::Both->value,
    ])->toBe([100, 200, 300]);
});

test('capability selection observes a newly loaded query alias without evaluating native arguments', function (): void {
    PlanSelectionNativeArgument::$constructions = 0;
    $registry = new AttributeDefinitionRegistry();
    $registry->register(new AttributeDefinition(
        PlanSelectionNativeTag::class,
        capabilities: [PlanSelectionDerivedCapability::class],
    ));
    $target = (new ReflectionFunction(__NAMESPACE__ . '\\planSelectionNative'))->getParameters()[0];
    $plan = (new AttributePlanBuilder($registry))->build($target);
    $usage = $plan->usages[0];
    /** @var class-string<PlanSelectionRootCapability> $alias */
    $alias = __NAMESPACE__ . '\\LatePlanSelectionCapability' . bin2hex(random_bytes(8));
    $autoloads = 0;
    $loader = static function (string $requested) use ($alias, &$autoloads): void {
        if ($requested === $alias) {
            ++$autoloads;
            class_alias(PlanSelectionRootCapability::class, $alias);
        }
    };
    spl_autoload_register($loader, true, true);

    try {
        expect($plan->has(PlanSelectionRootCapability::class))->toBeTrue()
            ->and($plan->one(PlanSelectionRootCapability::class))->toBe($usage)
            ->and($plan->has($alias))->toBeFalse()
            ->and($plan->one($alias))->toBeNull()
            ->and($autoloads)->toBe(0)
            ->and(PlanSelectionNativeArgument::$constructions)->toBe(0);

        expect(interface_exists($alias))->toBeTrue()
            ->and($plan->has($alias))->toBeTrue()
            ->and($plan->one($alias))->toBe($usage)
            ->and($autoloads)->toBe(1)
            ->and(PlanSelectionNativeArgument::$constructions)->toBe(0);
    } finally {
        spl_autoload_unregister($loader);
        PlanSelectionNativeArgument::$constructions = 0;
    }
});

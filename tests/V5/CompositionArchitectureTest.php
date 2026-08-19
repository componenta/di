<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use ReflectionMethod;
use Reflector;

use function Componenta\DI\compiled_factory_pipeline_fingerprint;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class RuleA {}

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class RuleB {}

final class NoopAttributeHandler implements AttributeHandlerInterface
{
    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void {}
}

final class VersionOneHandler implements AttributeHandlerInterface
{
    public const int SEMANTIC_VERSION = 1;
    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void {}
}

final class VersionTwoHandler implements AttributeHandlerInterface
{
    public const int SEMANTIC_VERSION = 2;
    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void {}
}

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

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class AmbiguousFamilyAttribute implements AttributeFamilyOne, AttributeFamilyTwo {}

function compositionFingerprint(AttributeDefinitionRegistry $registry): string
{
    return compiled_factory_pipeline_fingerprint(
        $registry,
        new ParametersResolver(new AttributePlanBuilder($registry)),
    );
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

test('compiled fingerprint includes definition and handler semantics', function (): void {
    $first = new AttributeDefinitionRegistry();
    $first->register(new AttributeDefinition(
        RuleA::class,
        new VersionOneHandler(),
        version: 1,
    ));

    $definitionChanged = new AttributeDefinitionRegistry();
    $definitionChanged->register(new AttributeDefinition(
        RuleA::class,
        new VersionOneHandler(),
        version: 2,
    ));

    $handlerChanged = new AttributeDefinitionRegistry();
    $handlerChanged->register(new AttributeDefinition(
        RuleA::class,
        new VersionTwoHandler(),
        version: 1,
    ));

    $baseline = compositionFingerprint($first);

    expect(compositionFingerprint($definitionChanged))->not->toBe($baseline)
        ->and(compositionFingerprint($handlerChanged))->not->toBe($baseline);
});

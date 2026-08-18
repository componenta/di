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
use Componenta\DI\Attribute\Handler\AttributeHandlerInterface;
use Componenta\DI\Attribute\Handler\VersionedAttributeHandlerInterface;
use Componenta\DI\Compile\Factory\CompiledFactoryPipelineFingerprint;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Value\ValueFallbackRegistry;
use ReflectionMethod;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class RuleA {}

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class RuleB {}

final class NoopAttributeHandler implements AttributeHandlerInterface {}

final class VersionedNoopAttributeHandler implements VersionedAttributeHandlerInterface
{
    public function __construct(private readonly int $version) {}

    public function semanticVersion(): int|string
    {
        return $this->version;
    }
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

test('custom composition rules see the complete attribute set before execution', function (): void {
    $registry = new AttributeDefinitionRegistry();
    $handler = new NoopAttributeHandler();
    $registry->register(new AttributeDefinition(
        RuleA::class,
        $handler,
        rules: [new RequiresRuleB()],
    ));
    $registry->register(new AttributeDefinition(RuleB::class, $handler));
    $plans = new AttributePlanBuilder($registry);

    $missing = (new ReflectionMethod(RuleTarget::class, 'missing'))->getParameters()[0];
    $complete = (new ReflectionMethod(RuleTarget::class, 'complete'))->getParameters()[0];

    expect(fn() => $plans->build($missing))
        ->toThrow(AttributeCompositionException::class, 'RuleA requires RuleB')
        ->and($plans->build($complete)->usages)->toHaveCount(2);
});

test('plan memoization invalidates when registry semantics change', function (): void {
    $registry = new AttributeDefinitionRegistry();
    $handler = new NoopAttributeHandler();
    $registry->register(new AttributeDefinition(RuleA::class, $handler));
    $plans = new AttributePlanBuilder($registry);
    $parameter = (new ReflectionMethod(RuleTarget::class, 'complete'))->getParameters()[0];

    expect($plans->build($parameter)->usages)->toHaveCount(1);

    $registry->register(new AttributeDefinition(RuleB::class, $handler));

    expect($plans->build($parameter)->usages)->toHaveCount(2);
});

test('inherited semantic definitions never resolve by registration order when equally specific', function (): void {
    $registry = new AttributeDefinitionRegistry();
    $handler = new NoopAttributeHandler();
    $registry->register(new AttributeDefinition(AttributeFamilyOne::class, $handler));
    $registry->register(new AttributeDefinition(AttributeFamilyTwo::class, $handler));

    expect(fn() => $registry->definition(AmbiguousFamilyAttribute::class))
        ->toThrow(InvalidConfigurationException::class, 'multiple equally specific');
});

test('compiled fingerprint includes definition and handler semantic versions', function (): void {
    $fallbacks = new ValueFallbackRegistry();

    $first = new AttributeDefinitionRegistry();
    $first->register(new AttributeDefinition(
        RuleA::class,
        new VersionedNoopAttributeHandler(1),
        version: 1,
    ));

    $definitionChanged = new AttributeDefinitionRegistry();
    $definitionChanged->register(new AttributeDefinition(
        RuleA::class,
        new VersionedNoopAttributeHandler(1),
        version: 2,
    ));

    $handlerChanged = new AttributeDefinitionRegistry();
    $handlerChanged->register(new AttributeDefinition(
        RuleA::class,
        new VersionedNoopAttributeHandler(2),
        version: 1,
    ));

    $baseline = CompiledFactoryPipelineFingerprint::calculate($first, $fallbacks);

    expect(CompiledFactoryPipelineFingerprint::calculate($definitionChanged, $fallbacks))->not->toBe($baseline)
        ->and(CompiledFactoryPipelineFingerprint::calculate($handlerChanged, $fallbacks))->not->toBe($baseline);
});

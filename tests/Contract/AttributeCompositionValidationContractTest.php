<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Attribute\Composition\CapabilityPolicy;
use Componenta\DI\Exception\AttributeCompositionException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class ParameterOnlyAuditMarker {}

#[Attribute(Attribute::TARGET_ALL)]
final class CompositionFirst {}

#[Attribute(Attribute::TARGET_ALL)]
final class CompositionSecond {}

#[Attribute(Attribute::TARGET_ALL)]
final class CompositionMissing {}

#[CompositionFirst, CompositionSecond]
final class CompositionPair {}

test('unsupported attribute targets report their actual target kind', function (string $kind): void {
    $name = 'InvalidTarget_' . bin2hex(random_bytes(5));
    $declaration = match ($kind) {
        'class' => sprintf('#[ParameterOnlyAuditMarker] final class %s {}', $name),
        'property' => sprintf('final class %s { #[ParameterOnlyAuditMarker] public string $value; }', $name),
        'method' => sprintf('final class %s { #[ParameterOnlyAuditMarker] public function run(): void {} }', $name),
        default => throw new \LogicException('Unknown fixture target.'),
    };
    eval(sprintf('namespace %s; %s', __NAMESPACE__, $declaration));
    $class = __NAMESPACE__ . '\\' . $name;
    if (!class_exists($class)) {
        throw new \LogicException('The invalid attribute fixture was not declared.');
    }
    $target = match ($kind) {
        'property' => new ReflectionProperty($class, 'value'),
        'method' => new ReflectionMethod($class, 'run'),
        default => new ReflectionClass($class),
    };
    $registry = new AttributeDefinitionRegistry();
    $registry->register(new AttributeDefinition(ParameterOnlyAuditMarker::class));
    $builder = new AttributePlanBuilder($registry);

    expect(fn() => $builder->build($target))->toThrow(
        AttributeCompositionException::class,
        'Attribute "' . ParameterOnlyAuditMarker::class . '" cannot target ' . $kind . '.',
    );
})->with(['class', 'property', 'method']);

test('capability conflicts name every offending attribute in declaration order', function (ReflectionClass $target): void {
    $registry = new AttributeDefinitionRegistry();
    $registry->defineCapability(new CapabilityPolicy(ValueProvider::class, 1));
    $registry->register(new AttributeDefinition(CompositionFirst::class, capabilities: [ValueProvider::class]));
    $registry->register(new AttributeDefinition(CompositionSecond::class, capabilities: [ValueProvider::class]));
    $builder = new AttributePlanBuilder($registry);

    expect(fn() => $builder->build($target))->toThrow(
        AttributeCompositionException::class,
        CompositionPair::class . ' accepts at most 1 attribute(s) with capability ' . ValueProvider::class
            . '; found #[' . CompositionFirst::class . '], #[' . CompositionSecond::class . '].',
    );
})->with([new ReflectionClass(CompositionPair::class)]);

test('a satisfied requirement does not hide a later missing requirement', function (ReflectionClass $target): void {
    $registry = new AttributeDefinitionRegistry();
    $registry->register(new AttributeDefinition(
        CompositionFirst::class,
        requires: [CompositionSecond::class, CompositionMissing::class],
    ));
    $registry->register(new AttributeDefinition(CompositionSecond::class));
    $builder = new AttributePlanBuilder($registry);

    expect(fn() => $builder->build($target))->toThrow(
        AttributeCompositionException::class,
        CompositionPair::class . ' requires ' . CompositionMissing::class . ' because of #[' . CompositionFirst::class . '].',
    );
})->with([new ReflectionClass(CompositionPair::class)]);

test('an absent forbidden attribute does not hide a later present conflict', function (ReflectionClass $target): void {
    $registry = new AttributeDefinitionRegistry();
    $registry->register(new AttributeDefinition(
        CompositionFirst::class,
        forbids: [CompositionMissing::class, CompositionSecond::class],
    ));
    $registry->register(new AttributeDefinition(CompositionSecond::class));
    $builder = new AttributePlanBuilder($registry);

    expect(fn() => $builder->build($target))->toThrow(
        AttributeCompositionException::class,
        CompositionPair::class . ' forbids ' . CompositionSecond::class . ' together with #[' . CompositionFirst::class . '].',
    );
})->with([new ReflectionClass(CompositionPair::class)]);

test('repeated ordering constraints produce one precedence relation', function (ReflectionClass $target): void {
    $registry = new AttributeDefinitionRegistry();
    $registry->register(new AttributeDefinition(CompositionFirst::class, after: [CompositionSecond::class]));
    $registry->register(new AttributeDefinition(CompositionSecond::class, before: [CompositionFirst::class]));
    $plan = (new AttributePlanBuilder($registry))->build($target);

    expect(array_map(static fn(\Componenta\DI\Attribute\Composition\AttributeUsage $usage): string => $usage->attributeClass, $plan->usages))
        ->toBe([CompositionSecond::class, CompositionFirst::class]);
})->with([new ReflectionClass(CompositionPair::class)]);

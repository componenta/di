<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeCapabilityInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Attribute\Composition\CapabilityPolicy;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\Attribute\Header;
use Componenta\DI\Attribute\Init;
use Componenta\DI\Attribute\Inject;
use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
use Componenta\DI\Resolver\Parameter\ParameterAttributeValue;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Target\ParameterTarget;

final class ConflictingSourcesDto
{
    public function __construct(
        #[Header('X-Value'), ConfigAttribute('value')]
        public string $value,
    ) {}
}

final class ConflictingPropertyProviders
{
    #[Inject, Init([self::class, 'makeValue'])]
    public \stdClass $value;

    public static function makeValue(): \stdClass
    {
        return new \stdClass();
    }
}

#[Lazy, Proxy]
final class ConflictingCreationStrategies {}

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class CustomValue {}

final class CustomValueHandler implements ParameterAttributeHandlerInterface
{
    public function resolveParameter(
        object $attribute,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        AttributePlan $plan,
        ParameterAttributeValue $value,
    ): ParameterAttributeValue {
        return $value->resolved ? $value : ParameterAttributeValue::resolved('custom');
    }
}

final class CustomValueDto
{
    public function __construct(#[CustomValue] public string $value) {}
}

interface ExclusiveCapability extends AttributeCapabilityInterface {}

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ExclusiveA {}

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ExclusiveB {}

#[ExclusiveA, ExclusiveB]
final class CustomCapabilityConflict {}

test('all value providers share one exclusive semantic slot', function (): void {
    $container = (new ContainerBuilder())->build();

    expect(fn() => $container->make(ConflictingSourcesDto::class))
        ->toThrow(AttributeCompositionException::class);

    expect(fn() => $container->make(ConflictingPropertyProviders::class))
        ->toThrow(AttributeCompositionException::class);
});

test('creation strategies are exclusive independently of value providers', function (): void {
    $container = (new ContainerBuilder())->build();

    expect(fn() => $container->make(ConflictingCreationStrategies::class))
        ->toThrow(AttributeCompositionException::class);
});

test('third party parameter attributes execute through the shared attribute resolver', function (): void {
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(
            CustomValue::class,
            new CustomValueHandler(),
            capabilities: [ValueProvider::class],
        ))
        ->build();

    expect($container->make(CustomValueDto::class)->value)->toBe('custom');
});

test('third party capabilities can define their own cardinality without runtime behavior', function (): void {
    $container = (new ContainerBuilder())
        ->defineAttributeCapability(new CapabilityPolicy(ExclusiveCapability::class, 1))
        ->addAttributeDefinition(new AttributeDefinition(
            ExclusiveA::class,
            handler: null,
            capabilities: [ExclusiveCapability::class],
        ))
        ->addAttributeDefinition(new AttributeDefinition(
            ExclusiveB::class,
            handler: null,
            capabilities: [ExclusiveCapability::class],
        ))
        ->build();

    expect(fn() => $container->make(CustomCapabilityConflict::class))
        ->toThrow(AttributeCompositionException::class);
});

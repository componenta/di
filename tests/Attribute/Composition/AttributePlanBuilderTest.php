<?php

declare(strict_types=1);

use Componenta\DI\Attribute\Composition\AttributeCapabilityInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Attribute\Composition\Capability\ValueTransformer;
use Componenta\DI\Attribute\Composition\CapabilityPolicy;
use Componenta\DI\Attribute\Handler\AttributeHandlerInterface;
use Componenta\DI\Exception\AttributeCompositionException;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class CompositionSourceA {}

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class CompositionSourceB {}

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::IS_REPEATABLE)]
final readonly class CompositionTransform
{
    public function __construct(public string $name) {}
}

interface CompositionCustomCapability extends AttributeCapabilityInterface {}

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class CompositionRequiresCustom {}

final readonly class CompositionDummyHandler implements AttributeHandlerInterface {}

function compositionRegistry(): AttributeDefinitionRegistry
{
    $registry = new AttributeDefinitionRegistry();
    $handler = new CompositionDummyHandler();

    $registry->defineCapability(new CapabilityPolicy(ValueProvider::class, maxPerTarget: 1));
    $registry->defineCapability(new CapabilityPolicy(ValueTransformer::class));

    $registry->register(new AttributeDefinition(CompositionSourceA::class, $handler, [ValueProvider::class]));
    $registry->register(new AttributeDefinition(CompositionSourceB::class, $handler, [ValueProvider::class]));
    $registry->register(new AttributeDefinition(CompositionTransform::class, $handler, [ValueTransformer::class]));
    $registry->register(new AttributeDefinition(
        CompositionRequiresCustom::class,
        $handler,
        requires: [CompositionCustomCapability::class],
    ));

    $registry->seal();

    return $registry;
}

it('rejects two attributes occupying one singular semantic capability', function (): void {
    $callable = static function (
        #[CompositionSourceA]
        #[CompositionSourceB]
        string $value,
    ): void {};
    $parameter = (new ReflectionFunction($callable))->getParameters()[0];

    expect(fn() => (new AttributePlanBuilder(compositionRegistry()))->build($parameter))
        ->toThrow(AttributeCompositionException::class, ValueProvider::class);
});

it('keeps repeatable transforms in declaration order beside one provider', function (): void {
    $callable = static function (
        #[CompositionSourceA]
        #[CompositionTransform('trim')]
        #[CompositionTransform('int')]
        string $value,
    ): void {};
    $parameter = (new ReflectionFunction($callable))->getParameters()[0];
    $plan = (new AttributePlanBuilder(compositionRegistry()))->build($parameter);

    expect($plan->one(ValueProvider::class)?->attribute)
        ->toBeInstanceOf(CompositionSourceA::class)
        ->and(array_map(
            static fn($usage): string => $usage->attribute->name,
            $plan->all(ValueTransformer::class),
        ))->toBe(['trim', 'int']);
});

it('validates declarative capability requirements', function (): void {
    $callable = static function (#[CompositionRequiresCustom] string $value): void {};
    $parameter = (new ReflectionFunction($callable))->getParameters()[0];

    expect(fn() => (new AttributePlanBuilder(compositionRegistry()))->build($parameter))
        ->toThrow(AttributeCompositionException::class, CompositionCustomCapability::class);
});

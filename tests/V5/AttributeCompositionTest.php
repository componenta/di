<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeCapabilityInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Attribute\Composition\CapabilityPolicy;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\Attribute\Handler\AttributeHandlerInterface;
use Componenta\DI\Attribute\Handler\ValueProviderHandlerInterface;
use Componenta\DI\Attribute\Handler\ValueProviderPrecedence;
use Componenta\DI\Attribute\Header;
use Componenta\DI\Attribute\Init;
use Componenta\DI\Attribute\Inject;
use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Resolver\Target\ValueTargetInterface;
use Componenta\DI\Value\ValueContext;

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

final class CustomValueHandler implements ValueProviderHandlerInterface
{
    public ValueProviderPrecedence $precedence { get => ValueProviderPrecedence::ProviderFirst; }

    public function provide(object $attribute, ValueTargetInterface $target, ValueContext $context): mixed
    {
        return 'custom';
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

final readonly class MarkerHandler implements AttributeHandlerInterface {}

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

test('third party value providers use the same composition rules', function (): void {
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(
            CustomValue::class,
            new CustomValueHandler(),
            [ValueProvider::class],
        ))
        ->build();

    expect($container->make(CustomValueDto::class)->value)->toBe('custom');
});

test('third party capabilities can define their own cardinality', function (): void {
    $handler = new MarkerHandler();
    $container = (new ContainerBuilder())
        ->defineAttributeCapability(new CapabilityPolicy(ExclusiveCapability::class, 1))
        ->addAttributeDefinition(new AttributeDefinition(ExclusiveA::class, $handler, [ExclusiveCapability::class]))
        ->addAttributeDefinition(new AttributeDefinition(ExclusiveB::class, $handler, [ExclusiveCapability::class]))
        ->build();

    expect(fn() => $container->make(CustomCapabilityConflict::class))
        ->toThrow(AttributeCompositionException::class);
});

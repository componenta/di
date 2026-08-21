<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\Capability\CreationStrategy;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\AttributeCompositionException;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class ReplacementValueSourceA {}

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class ReplacementValueSourceB {}

final readonly class ReplacementValueSourceTarget
{
    public function __construct(
        #[ReplacementValueSourceA, ReplacementValueSourceB]
        public string $value = 'default',
    ) {}
}

test('core capability policies belong to the composition registry itself', function (): void {
    $registry = new AttributeDefinitionRegistry();

    expect($registry->policy(ValueProvider::class)->maxPerTarget)->toBe(1)
        ->and($registry->policy(CreationStrategy::class)->maxPerTarget)->toBe(1);
});

test('replacing attribute definitions does not disable core capability cardinality', function (): void {
    $container = (new ContainerBuilder())
        ->replaceAttributeDefinitions()
        ->addAttributeDefinition(new AttributeDefinition(
            ReplacementValueSourceA::class,
            capabilities: [ValueProvider::class],
        ))
        ->addAttributeDefinition(new AttributeDefinition(
            ReplacementValueSourceB::class,
            capabilities: [ValueProvider::class],
        ))
        ->build();

    expect(fn() => $container->make(ReplacementValueSourceTarget::class))
        ->toThrow(AttributeCompositionException::class, 'accepts at most 1 attribute');
});

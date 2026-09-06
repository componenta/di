<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeCapabilityInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\CapabilityPolicy;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;

interface IdentityCapability extends AttributeCapabilityInterface {}

class_alias(IdentityCapability::class, __NAMESPACE__ . '\\IdentityCapabilityAlias');

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class ExclusiveIdentityMarker {}

#[ExclusiveIdentityMarker, ExclusiveIdentityMarker]
final class ExclusiveIdentityTarget {}

/** @return class-string<AttributeCapabilityInterface> */
function capabilitySpelling(string $variant): string
{
    $name = match ($variant) {
        'canonical' => IdentityCapability::class,
        'case' => strtolower(IdentityCapability::class),
        'separator' => '\\' . IdentityCapability::class,
        'alias' => __NAMESPACE__ . '\\IdentityCapabilityAlias',
        default => throw new LogicException('Unknown capability name variant.'),
    };
    if (!is_a($name, AttributeCapabilityInterface::class, true)) {
        throw new LogicException('Expected a valid capability interface.');
    }
    return $name;
}

it('rejects conflicting policies for the same PHP capability', function (string $variant): void {
    expect(fn() => (new ContainerBuilder())
        ->defineAttributeCapability(new CapabilityPolicy(IdentityCapability::class, 1))
        ->defineAttributeCapability(new CapabilityPolicy(capabilitySpelling($variant), 2))
        ->build())->toThrow(InvalidConfigurationException::class, 'already has a different composition policy');
})->with(['canonical', 'case', 'separator', 'alias']);

it('accepts equivalent policies and enforces their shared cardinality', function (string $variant): void {
    $container = (new ContainerBuilder())
        ->defineAttributeCapability(new CapabilityPolicy(IdentityCapability::class, 1))
        ->defineAttributeCapability(new CapabilityPolicy(capabilitySpelling($variant), 1))
        ->addAttributeDefinition(new AttributeDefinition(ExclusiveIdentityMarker::class, capabilities: [capabilitySpelling($variant)]))
        ->build();

    expect(fn() => $container->make(ExclusiveIdentityTarget::class))
        ->toThrow(AttributeCompositionException::class, 'accepts at most 1 attribute(s)');
})->with(['canonical', 'case', 'separator', 'alias']);

it('looks up capability policies by PHP identity', function (string $variant): void {
    $registry = new \Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry();
    $registry->defineCapability(new CapabilityPolicy(IdentityCapability::class, 1));

    expect($registry->policy(capabilitySpelling($variant))->maxPerTarget)->toBe(1);
})->with(['case', 'separator', 'alias']);

it('does not create a separate implicit policy for an alternative capability spelling', function (string $variant): void {
    $registry = new \Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry();
    $registry->register(new AttributeDefinition(ExclusiveIdentityMarker::class, capabilities: [capabilitySpelling($variant)]));

    expect(fn() => $registry->defineCapability(new CapabilityPolicy(IdentityCapability::class, 1)))
        ->toThrow(InvalidConfigurationException::class, 'already has a different composition policy');
})->with(['case', 'separator', 'alias']);

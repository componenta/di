<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeCapabilityInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\Capability\ConstructorPolicy;
use Componenta\DI\Attribute\Composition\Capability\CreationStrategy;
use Componenta\DI\Attribute\Composition\Capability\LifecycleHook;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Attribute\Composition\Capability\ValueTransformer;
use Componenta\DI\Attribute\Composition\CapabilityPolicy;
use Componenta\DI\Exception\InvalidConfigurationException;

final class ExtensionAuditCapability implements AttributeCapabilityInterface {}
final class UnregisteredAuditCapability implements AttributeCapabilityInterface {}

#[Attribute(Attribute::TARGET_ALL)]
final class ExtensionAuditMarker {}

test('core capability policies expose singular providers and repeatable transformations and lifecycle hooks', function (): void {
    $registry = new AttributeDefinitionRegistry();
    $policies = [];
    foreach ($registry->policies() as $policy) {
        $policies[$policy->capability] = $policy->maxPerTarget;
    }

    expect($policies)->toBe([
        ValueProvider::class => 1,
        ValueTransformer::class => null,
        CreationStrategy::class => 1,
        ConstructorPolicy::class => 1,
        LifecycleHook::class => null,
    ]);
});

test('explicit capability policy survives attribute registration and equivalent redefinition', function (): void {
    $registry = new AttributeDefinitionRegistry();
    $policy = new CapabilityPolicy(ExtensionAuditCapability::class, 2);
    $registry->defineCapability($policy);
    $registry->register(new AttributeDefinition(ExtensionAuditMarker::class, capabilities: [ExtensionAuditCapability::class]));
    $registry->defineCapability(new CapabilityPolicy(ExtensionAuditCapability::class, 2));

    expect($registry->policy(ExtensionAuditCapability::class))->toBe($policy)
        ->and($registry->policy(strtolower(ExtensionAuditCapability::class)))->toBe($policy)
        ->and($registry->policy(UnregisteredAuditCapability::class)->maxPerTarget)->toBeNull()
        ->and($registry->policy(UnregisteredAuditCapability::class)->capability)->toBe(UnregisteredAuditCapability::class)
        ->and($registry->policies())->toHaveCount(6);
});

test('attribute registration creates an unlimited custom capability policy and rejects a conflicting later limit', function (): void {
    $registry = new AttributeDefinitionRegistry();
    $registry->register(new AttributeDefinition(ExtensionAuditMarker::class, capabilities: [ExtensionAuditCapability::class]));

    expect($registry->policy(ExtensionAuditCapability::class)->maxPerTarget)->toBeNull()
        ->and(fn() => $registry->defineCapability(new CapabilityPolicy(ExtensionAuditCapability::class, 1)))
        ->toThrow(
            InvalidConfigurationException::class,
            'Capability "' . ExtensionAuditCapability::class . '" already has a different composition policy.',
        )
        ->and($registry->policy(ExtensionAuditCapability::class)->maxPerTarget)->toBeNull();
});

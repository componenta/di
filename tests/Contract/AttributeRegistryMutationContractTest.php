<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeCapabilityInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\CapabilityPolicy;
use Componenta\DI\Exception\InvalidConfigurationException;

#[Attribute(Attribute::TARGET_ALL)]
class RegistryAncestorMarker {}

#[Attribute(Attribute::TARGET_ALL)]
class RegistryParentMarker extends RegistryAncestorMarker {}

#[Attribute(Attribute::TARGET_ALL)]
final class RegistryLeafMarker extends RegistryParentMarker {}

interface RegistryAuditCapability extends AttributeCapabilityInterface {}

test('registry selects the nearest inherited definition regardless of registration order', function (bool $reverse): void {
    $ancestor = new AttributeDefinition(RegistryAncestorMarker::class);
    $parent = new AttributeDefinition(RegistryParentMarker::class);
    $registry = new AttributeDefinitionRegistry();
    foreach ($reverse ? [$parent, $ancestor] : [$ancestor, $parent] as $definition) {
        $registry->register($definition);
    }

    expect($registry->definition(RegistryLeafMarker::class))->toBe($parent)
        ->and($registry->definition(RegistryAncestorMarker::class))->toBe($ancestor);
})->with([false, true]);

test('registry sealing prevents later definition and capability changes', function (string $operation): void {
    $registry = new AttributeDefinitionRegistry();
    $registry->seal();

    expect(function () use ($registry, $operation): void {
        if ($operation === 'definition') {
            $registry->register(new AttributeDefinition(RegistryLeafMarker::class));
        } else {
            $registry->defineCapability(new CapabilityPolicy(RegistryAuditCapability::class, 1));
        }
    })->toThrow(InvalidConfigurationException::class, 'Attribute definition registry is sealed.');
})->with(['definition', 'capability']);

test('registry exposes registered definitions as an ordered list', function (): void {
    $first = new AttributeDefinition(RegistryAncestorMarker::class);
    $second = new AttributeDefinition(RegistryParentMarker::class);
    $registry = new AttributeDefinitionRegistry();
    $registry->register($first);
    $registry->register($second);

    expect($registry->definitions())->toBe([$first, $second]);
});

<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Composition;

use Componenta\DI\Internal\AttributeDefinitions;
use Componenta\DI\Internal\AttributeRegistryScope;

/**
 * Registers attribute definitions and capability policies for one composition.
 */
final class AttributeDefinitionRegistry extends AttributeRegistryScope
{
    public function __construct(bool $targetedDiscovery = false)
    {
        parent::__construct(new AttributeDefinitions($targetedDiscovery));
    }

    public function register(AttributeDefinition $definition): void
    {
        $this->definitions->register($definition);
    }

    public function defineCapability(CapabilityPolicy $policy): void
    {
        $this->definitions->defineCapability($policy);
    }

    public function seal(): void
    {
        $this->definitions->seal();
    }

    /** @param class-string $attribute */
    public function definition(string $attribute): ?AttributeDefinition
    {
        return $this->definitions->definition($attribute);
    }

    /** @param class-string<AttributeCapabilityInterface> $capability */
    public function policy(string $capability): CapabilityPolicy
    {
        return $this->definitions->policy($capability);
    }

    /** @return list<AttributeDefinition> */
    public function definitions(): array
    {
        return $this->definitions->definitions();
    }

    /** @return list<CapabilityPolicy> */
    public function policies(): array
    {
        return $this->definitions->policies();
    }
}

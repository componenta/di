<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Composition;

use Componenta\DI\Exception\InvalidConfigurationException;
use LogicException;

/** Mutable composition-time registry, sealed before the container becomes usable. */
final class AttributeDefinitionRegistry
{
    /** @var array<class-string, AttributeDefinition> */
    private array $definitions = [];

    /** @var array<class-string<AttributeCapabilityInterface>, CapabilityPolicy> */
    private array $policies = [];

    private bool $sealed = false;

    public function register(AttributeDefinition $definition): void
    {
        $this->assertMutable();
        if (isset($this->definitions[$definition->attribute])) {
            throw new InvalidConfigurationException(sprintf(
                'Attribute "%s" already has a semantic definition.',
                $definition->attribute,
            ));
        }

        foreach ($definition->capabilities as $capability) {
            if (!is_a($capability, AttributeCapabilityInterface::class, true)) {
                throw new InvalidConfigurationException(sprintf(
                    'Attribute capability "%s" must implement %s.',
                    $capability,
                    AttributeCapabilityInterface::class,
                ));
            }
            $this->policies[$capability] ??= new CapabilityPolicy($capability);
        }

        $this->definitions[$definition->attribute] = $definition;
    }

    public function defineCapability(CapabilityPolicy $policy): void
    {
        $this->assertMutable();
        $existing = $this->policies[$policy->capability] ?? null;
        if ($existing !== null && $existing != $policy) {
            throw new InvalidConfigurationException(sprintf(
                'Capability "%s" already has a different composition policy.',
                $policy->capability,
            ));
        }
        $this->policies[$policy->capability] = $policy;
    }

    public function seal(): void
    {
        $this->sealed = true;
    }

    /** @param class-string $attributeClass */
    public function definition(string $attributeClass): ?AttributeDefinition
    {
        if (isset($this->definitions[$attributeClass])) {
            return $this->definitions[$attributeClass];
        }

        foreach ($this->definitions as $registered => $definition) {
            if (is_a($attributeClass, $registered, true)) {
                return $definition;
            }
        }
        return null;
    }

    /** @param class-string<AttributeCapabilityInterface> $capability */
    public function policy(string $capability): CapabilityPolicy
    {
        return $this->policies[$capability] ?? new CapabilityPolicy($capability);
    }

    /** @return list<AttributeDefinition> */
    public function definitions(): array
    {
        $result = [];
        foreach ($this->definitions as $definition) {
            $result[] = $definition;
        }
        return $result;
    }

    /** @return list<CapabilityPolicy> */
    public function policies(): array
    {
        $result = [];
        foreach ($this->policies as $policy) {
            $result[] = $policy;
        }
        return $result;
    }

    private function assertMutable(): void
    {
        if ($this->sealed) {
            throw new LogicException('Attribute definition registry is sealed.');
        }
    }
}

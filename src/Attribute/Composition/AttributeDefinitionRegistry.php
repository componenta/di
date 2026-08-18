<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Composition;

use Componenta\DI\Exception\InvalidConfigurationException;
use LogicException;

/**
 * Mutable composition-root registry, sealed before runtime use.
 *
 * Definitions are selected by exact attribute class first. A registered base
 * class/interface may own subclasses; ambiguous unrelated matches are rejected.
 */
final class AttributeDefinitionRegistry
{
    /** @var array<class-string, AttributeDefinition> */
    private array $definitions = [];

    /** @var array<class-string<AttributeCapabilityInterface>, CapabilityPolicy> */
    private array $policies = [];

    /** @var array<class-string, AttributeDefinition|null> */
    private array $resolutionCache = [];

    private bool $sealed = false;

    private int $revision = 0;

    public int $version {
        get => $this->revision;
    }

    public function register(AttributeDefinition $definition): void
    {
        $this->assertMutable();

        if (isset($this->definitions[$definition->attribute])) {
            throw new InvalidConfigurationException(sprintf(
                'Attribute "%s" already has a DI definition.',
                $definition->attribute,
            ));
        }

        $this->definitions[$definition->attribute] = $definition;
        $this->resolutionCache = [];
        ++$this->revision;
    }

    public function defineCapability(CapabilityPolicy $policy): void
    {
        $this->assertMutable();

        if (isset($this->policies[$policy->capability])) {
            throw new InvalidConfigurationException(sprintf(
                'Capability "%s" already has a composition policy.',
                $policy->capability,
            ));
        }

        $this->policies[$policy->capability] = $policy;
        ++$this->revision;
    }

    public function seal(): void
    {
        $this->sealed = true;
    }

    /** @param class-string $attributeClass */
    public function definition(string $attributeClass): ?AttributeDefinition
    {
        if (array_key_exists($attributeClass, $this->resolutionCache)) {
            return $this->resolutionCache[$attributeClass];
        }

        if (isset($this->definitions[$attributeClass])) {
            return $this->resolutionCache[$attributeClass] = $this->definitions[$attributeClass];
        }

        $matches = [];

        foreach ($this->definitions as $registered => $definition) {
            if (is_a($attributeClass, $registered, true)) {
                $matches[$registered] = $definition;
            }
        }

        if ($matches === []) {
            return $this->resolutionCache[$attributeClass] = null;
        }

        $mostSpecific = [];

        foreach (array_keys($matches) as $candidate) {
            $shadowed = false;

            foreach (array_keys($matches) as $other) {
                if ($candidate !== $other && is_a($other, $candidate, true)) {
                    $shadowed = true;
                    break;
                }
            }

            if (!$shadowed) {
                $mostSpecific[$candidate] = $matches[$candidate];
            }
        }

        if (count($mostSpecific) !== 1) {
            throw new InvalidConfigurationException(sprintf(
                'Attribute "%s" matches multiple unrelated DI definitions: %s.',
                $attributeClass,
                implode(', ', array_keys($mostSpecific)),
            ));
        }

        return $this->resolutionCache[$attributeClass] = array_values($mostSpecific)[0];
    }

    /** @param class-string<AttributeCapabilityInterface> $capability */
    public function policy(string $capability): CapabilityPolicy
    {
        return $this->policies[$capability] ?? new CapabilityPolicy($capability);
    }

    /** @return list<AttributeDefinition> */
    public function definitions(): array
    {
        return array_values($this->definitions);
    }

    /** @return list<CapabilityPolicy> */
    public function policies(): array
    {
        return array_values($this->policies);
    }

    private function assertMutable(): void
    {
        if ($this->sealed) {
            throw new LogicException('Attribute definition registry is sealed.');
        }
    }
}

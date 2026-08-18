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
    private int $generation = 0;

    public int $revision {
        get => $this->generation;
    }

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
        ++$this->generation;
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
        if ($existing === null) {
            $this->policies[$policy->capability] = $policy;
            ++$this->generation;
        }
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

        /** @var array<class-string, AttributeDefinition> $matches */
        $matches = [];
        foreach ($this->definitions as $registered => $definition) {
            if (is_a($attributeClass, $registered, true)) {
                $matches[$registered] = $definition;
            }
        }

        if ($matches === []) {
            return null;
        }
        if (count($matches) === 1) {
            return array_values($matches)[0];
        }

        // Keep only the most-specific registrations. A class/interface that is
        // an ancestor of another matching registration cannot win.
        foreach (array_keys($matches) as $candidate) {
            foreach (array_keys($matches) as $other) {
                if ($candidate === $other) {
                    continue;
                }
                if (is_a($other, $candidate, true)) {
                    unset($matches[$candidate]);
                    break;
                }
            }
        }

        if (count($matches) === 1) {
            return array_values($matches)[0];
        }

        throw new InvalidConfigurationException(sprintf(
            'Attribute "%s" matches multiple equally specific semantic definitions: %s.',
            $attributeClass,
            implode(', ', array_keys($matches)),
        ));
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

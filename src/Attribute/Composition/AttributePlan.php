<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Composition;

use Componenta\DI\Exception\AttributeCompositionException;
use Reflector;

/** Validated, immutable semantic plan for all registered DI attributes on one target. */
final readonly class AttributePlan
{
    /** @param list<AttributeUsage> $usages */
    public function __construct(
        public Reflector $target,
        public array $usages,
    ) {}

    /**
     * @param class-string<AttributeCapabilityInterface> $capability
     * @return list<AttributeUsage>
     */
    public function all(string $capability): array
    {
        $matches = [];
        foreach ($this->usages as $usage) {
            if ($usage->hasCapability($capability)) {
                $matches[] = $usage;
            }
        }
        return $matches;
    }

    /** @param class-string<AttributeCapabilityInterface> $capability */
    public function one(string $capability): ?AttributeUsage
    {
        $usages = $this->all($capability);
        if (count($usages) > 1) {
            throw new AttributeCompositionException(sprintf(
                'Capability "%s" is not singular in this plan.',
                $capability,
            ));
        }

        return $usages[0] ?? null;
    }

    /** @param class-string<AttributeCapabilityInterface> $capability */
    public function has(string $capability): bool
    {
        return $this->all($capability) !== [];
    }

    /**
     * @param class-string $attributeClass
     * @return list<AttributeUsage>
     */
    public function attributes(string $attributeClass): array
    {
        $matches = [];
        foreach ($this->usages as $usage) {
            if (is_a($usage->attributeClass, $attributeClass, true)) {
                $matches[] = $usage;
            }
        }

        return $matches;
    }
}

<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Composition;

use Componenta\DI\Exception\AttributeCompositionException;
use Reflector;

/** Validated, immutable semantic plan for all registered DI attributes on one target. */
final readonly class AttributePlan
{
    /** @var array<class-string<AttributeCapabilityInterface>, list<AttributeUsage>> */
    private array $byCapability;

    /** @var array<class-string, list<AttributeUsage>> */
    private array $byAttribute;

    /** @param list<AttributeUsage> $usages */
    public function __construct(
        public Reflector $target,
        public array $usages,
    ) {
        /** @var array<class-string<AttributeCapabilityInterface>, list<AttributeUsage>> $byCapability */
        $byCapability = [];
        /** @var array<class-string, list<AttributeUsage>> $byAttribute */
        $byAttribute = [];

        foreach ($usages as $usage) {
            $byAttribute[$usage->attribute::class][] = $usage;
            foreach ($usage->definition->capabilities as $capability) {
                $byCapability[$capability][] = $usage;
            }
        }

        $this->byCapability = $byCapability;
        $this->byAttribute = $byAttribute;
    }

    /**
     * @param class-string<AttributeCapabilityInterface> $capability
     * @return list<AttributeUsage>
     */
    public function all(string $capability): array
    {
        $matches = [];
        $seen = [];

        foreach ($this->byCapability as $registered => $usages) {
            if (!is_a($registered, $capability, true)) {
                continue;
            }

            foreach ($usages as $usage) {
                $id = spl_object_id($usage);
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
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
        foreach ($this->byAttribute as $class => $usages) {
            if (!is_a($class, $attributeClass, true)) {
                continue;
            }
            foreach ($usages as $usage) {
                $matches[] = $usage;
            }
        }

        return $matches;
    }
}

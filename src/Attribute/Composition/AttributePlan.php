<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Composition;

use LogicException;
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
        $byCapability = [];
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

    /** @param class-string<AttributeCapabilityInterface> $capability @return list<AttributeUsage> */
    public function all(string $capability): array
    {
        return $this->byCapability[$capability] ?? [];
    }

    /** @param class-string<AttributeCapabilityInterface> $capability */
    public function one(string $capability): ?AttributeUsage
    {
        $usages = $this->all($capability);

        if (count($usages) > 1) {
            throw new LogicException(sprintf(
                'Capability "%s" is not singular in this plan.',
                $capability,
            ));
        }

        return $usages[0] ?? null;
    }

    /** @param class-string<AttributeCapabilityInterface> $capability */
    public function has(string $capability): bool
    {
        return isset($this->byCapability[$capability]);
    }

    /** @param class-string $attributeClass @return list<AttributeUsage> */
    public function attributes(string $attributeClass): array
    {
        $matches = [];

        foreach ($this->byAttribute as $class => $usages) {
            if (is_a($class, $attributeClass, true)) {
                array_push($matches, ...$usages);
            }
        }

        return $matches;
    }
}

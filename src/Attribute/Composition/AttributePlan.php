<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Composition;

use Closure;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Internal\ReflectionSnapshot;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;

/** Validated, immutable semantic plan for all registered DI attributes on one target. */
final class AttributePlan
{
    /** @var Closure|ReflectionClass<covariant object>|ReflectionMethod|ReflectionParameter|ReflectionProperty */
    private readonly Closure|ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $declaration;

    /** @var ReflectionClass<covariant object>|ReflectionMethod|ReflectionParameter|ReflectionProperty */
    public ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target {
        get => ReflectionSnapshot::target($this->declaration);
    }

    /**
     * @param Closure|ReflectionClass<covariant object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $target
     * @param list<AttributeUsage> $usages
     */
    public function __construct(
        Closure|ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target,
        public readonly array $usages,
    ) {
        $this->declaration = ReflectionSnapshot::planDeclaration($target);
    }

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
        $first = null;
        $count = 0;
        foreach ($this->usages as $usage) {
            if ($usage->hasCapability($capability)) {
                $first ??= $usage;
                ++$count;
            }
        }
        if ($count > 1) {
            throw new AttributeCompositionException(sprintf(
                'Capability "%s" is not singular in this plan.',
                $capability,
            ));
        }

        return $first;
    }

    /** @param class-string<AttributeCapabilityInterface> $capability */
    public function has(string $capability): bool
    {
        $found = false;
        foreach ($this->usages as $usage) {
            if ($usage->hasCapability($capability)) {
                $found = true;
            }
        }
        return $found;
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

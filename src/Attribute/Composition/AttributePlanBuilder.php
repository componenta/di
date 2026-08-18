<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Composition;

use Componenta\DI\Exception\AttributeCompositionException;
use ReflectionAttribute;
use Reflector;

/** Builds, validates and orders the semantic DI attribute plan for one target. */
final readonly class AttributePlanBuilder
{
    public function __construct(
        private AttributeDefinitionRegistry $registry,
    ) {}

    public function build(Reflector $target): AttributePlan
    {
        $usages = [];

        /** @var ReflectionAttribute<object> $reflectionAttribute */
        foreach ($target->getAttributes() as $declarationOrder => $reflectionAttribute) {
            /** @var class-string $attributeClass */
            $attributeClass = $reflectionAttribute->getName();
            $definition = $this->registry->definition($attributeClass);

            if ($definition === null) {
                continue;
            }

            try {
                $attribute = $reflectionAttribute->newInstance();
            } catch (\Throwable $error) {
                throw new AttributeCompositionException(sprintf(
                    'Cannot instantiate DI attribute "%s" on %s: %s',
                    $attributeClass,
                    self::targetName($target),
                    $error->getMessage(),
                ), previous: $error);
            }

            $usages[] = new AttributeUsage(
                attribute: $attribute,
                definition: $definition,
                target: $target,
                declarationOrder: $declarationOrder,
            );
        }

        $this->assertCapabilityCardinality($target, $usages);
        $this->assertDependencies($target, $usages);

        return new AttributePlan($target, $this->ordered($target, $usages));
    }

    /** @param list<AttributeUsage> $usages */
    private function assertCapabilityCardinality(Reflector $target, array $usages): void
    {
        $byCapability = [];

        foreach ($usages as $usage) {
            foreach ($usage->definition->capabilities as $capability) {
                $byCapability[$capability][] = $usage;
            }
        }

        foreach ($byCapability as $capability => $members) {
            $max = $this->registry->policy($capability)->maxPerTarget;

            if ($max === null || count($members) <= $max) {
                continue;
            }

            throw new AttributeCompositionException(sprintf(
                'DI target %s has %d attributes contributing capability "%s", but at most %d are allowed: %s.',
                self::targetName($target),
                count($members),
                $capability,
                $max,
                implode(', ', array_map(
                    static fn(AttributeUsage $usage): string => '#[' . $usage->attribute::class . ']',
                    $members,
                )),
            ));
        }
    }

    /** @param list<AttributeUsage> $usages */
    private function assertDependencies(Reflector $target, array $usages): void
    {
        foreach ($usages as $usage) {
            foreach ($usage->definition->requires as $selector) {
                if ($this->matching($selector, $usages, $usage) !== []) {
                    continue;
                }

                throw new AttributeCompositionException(sprintf(
                    'DI attribute "#[%s]" on %s requires "%s".',
                    $usage->attribute::class,
                    self::targetName($target),
                    $selector,
                ));
            }

            foreach ($usage->definition->forbids as $selector) {
                $conflicts = $this->matching($selector, $usages, $usage);

                if ($conflicts === []) {
                    continue;
                }

                throw new AttributeCompositionException(sprintf(
                    'DI attribute "#[%s]" on %s forbids "%s"; conflicting attributes: %s.',
                    $usage->attribute::class,
                    self::targetName($target),
                    $selector,
                    implode(', ', array_map(
                        static fn(AttributeUsage $conflict): string => '#[' . $conflict->attribute::class . ']',
                        $conflicts,
                    )),
                ));
            }
        }
    }

    /** @param list<AttributeUsage> $usages @return list<AttributeUsage> */
    private function ordered(Reflector $target, array $usages): array
    {
        $count = count($usages);

        if ($count < 2) {
            return $usages;
        }

        $edges = [];
        $indegree = array_fill(0, $count, 0);

        foreach ($usages as $index => $usage) {
            foreach ($usage->definition->before as $selector) {
                foreach ($this->matchingIndexes($selector, $usages, $index) as $other) {
                    self::addEdge($edges, $indegree, $index, $other);
                }
            }

            foreach ($usage->definition->after as $selector) {
                foreach ($this->matchingIndexes($selector, $usages, $index) as $other) {
                    self::addEdge($edges, $indegree, $other, $index);
                }
            }
        }

        $result = [];
        $remaining = array_fill(0, $count, true);

        while (count($result) < $count) {
            $next = null;

            foreach ($usages as $index => $usage) {
                if (!isset($remaining[$index]) || $indegree[$index] !== 0) {
                    continue;
                }

                if ($next === null || $usage->declarationOrder < $usages[$next]->declarationOrder) {
                    $next = $index;
                }
            }

            if ($next === null) {
                throw new AttributeCompositionException(sprintf(
                    'DI attribute ordering on %s contains a cycle.',
                    self::targetName($target),
                ));
            }

            $result[] = $usages[$next];
            unset($remaining[$next]);

            foreach (array_keys($edges[$next] ?? []) as $targetIndex) {
                --$indegree[$targetIndex];
            }
        }

        return $result;
    }

    /** @param list<AttributeUsage> $usages @return list<AttributeUsage> */
    private function matching(string $selector, array $usages, AttributeUsage $exclude): array
    {
        $matches = [];

        foreach ($usages as $usage) {
            if ($usage !== $exclude && self::matches($usage, $selector)) {
                $matches[] = $usage;
            }
        }

        return $matches;
    }

    /** @param list<AttributeUsage> $usages @return list<int> */
    private function matchingIndexes(string $selector, array $usages, int $exclude): array
    {
        $matches = [];

        foreach ($usages as $index => $usage) {
            if ($index !== $exclude && self::matches($usage, $selector)) {
                $matches[] = $index;
            }
        }

        return $matches;
    }

    private static function matches(AttributeUsage $usage, string $selector): bool
    {
        if (is_a($selector, AttributeCapabilityInterface::class, true)) {
            return in_array($selector, $usage->definition->capabilities, true);
        }

        return is_a($usage->attribute::class, $selector, true);
    }

    /** @param array<int, array<int, true>> $edges @param list<int> $indegree */
    private static function addEdge(array &$edges, array &$indegree, int $from, int $to): void
    {
        if (isset($edges[$from][$to])) {
            return;
        }

        $edges[$from][$to] = true;
        ++$indegree[$to];
    }

    private static function targetName(Reflector $target): string
    {
        return match (true) {
            $target instanceof \ReflectionParameter => sprintf(
                '$%s of %s',
                $target->getName(),
                $target->getDeclaringClass() !== null
                    ? $target->getDeclaringClass()->getName() . '::' . $target->getDeclaringFunction()->getName() . '()'
                    : $target->getDeclaringFunction()->getName() . '()',
            ),
            $target instanceof \ReflectionProperty => sprintf(
                '%s::$%s',
                $target->getDeclaringClass()->getName(),
                $target->getName(),
            ),
            $target instanceof \ReflectionMethod => sprintf(
                '%s::%s()',
                $target->getDeclaringClass()->getName(),
                $target->getName(),
            ),
            $target instanceof \ReflectionClass => $target->getName(),
            default => get_debug_type($target),
        };
    }
}

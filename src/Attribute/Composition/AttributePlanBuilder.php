<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Composition;

use Componenta\DI\Exception\AttributeCompositionException;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;
use Reflector;

/** Builds, validates and orders the semantic DI attribute plan for one target. */
final readonly class AttributePlanBuilder
{
    public function __construct(private AttributeDefinitionRegistry $registry) {}

    public function build(Reflector $target): AttributePlan
    {
        self::assertSupportedTarget($target);
        $usages = [];

        foreach ($target->getAttributes() as $index => $reflectionAttribute) {
            /** @var class-string $attributeClass */
            $attributeClass = $reflectionAttribute->getName();
            $definition = $this->registry->definition($attributeClass);
            if ($definition === null) {
                continue;
            }

            $usages[] = new AttributeUsage(
                $reflectionAttribute->newInstance(),
                $definition,
                $target,
                $index,
            );
        }

        $this->assertCapabilityCardinality($target, $usages);
        $this->assertDependencies($target, $usages);

        return new AttributePlan($target, $this->ordered($target, $usages));
    }

    /** @param list<AttributeUsage> $usages */
    private function assertCapabilityCardinality(Reflector $target, array $usages): void
    {
        /** @var array<class-string<AttributeCapabilityInterface>, list<AttributeUsage>> $byCapability */
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
                '%s accepts at most %d attribute(s) with capability %s; found %s.',
                self::targetName($target),
                $max,
                $capability,
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
                    '%s requires %s because of #[%s].',
                    self::targetName($target),
                    $selector,
                    $usage->attribute::class,
                ));
            }

            foreach ($usage->definition->forbids as $selector) {
                $matches = $this->matching($selector, $usages, $usage);
                if ($matches === []) {
                    continue;
                }
                throw new AttributeCompositionException(sprintf(
                    '%s forbids %s together with #[%s].',
                    self::targetName($target),
                    $selector,
                    $usage->attribute::class,
                ));
            }
        }
    }

    /**
     * @param list<AttributeUsage> $usages
     * @return list<AttributeUsage>
     */
    private function ordered(Reflector $target, array $usages): array
    {
        if (count($usages) < 2) {
            return $usages;
        }

        /** @var array<int, array<int, true>> $edges */
        $edges = [];
        /** @var array<int, int> $indegree */
        $indegree = array_fill(0, count($usages), 0);

        foreach ($usages as $from => $usage) {
            foreach ($usage->definition->before as $selector) {
                foreach ($this->matchingIndexes($selector, $usages, $from) as $to) {
                    self::addEdge($edges, $indegree, $from, $to);
                }
            }
            foreach ($usage->definition->after as $selector) {
                foreach ($this->matchingIndexes($selector, $usages, $from) as $other) {
                    self::addEdge($edges, $indegree, $other, $from);
                }
            }
        }

        /** @var array<int, true> $remaining */
        $remaining = array_fill_keys(array_keys($usages), true);
        $ordered = [];

        while ($remaining !== []) {
            $next = null;
            foreach (array_keys($remaining) as $candidate) {
                if ($indegree[$candidate] !== 0) {
                    continue;
                }
                if ($next === null
                    || $usages[$candidate]->declarationOrder < $usages[$next]->declarationOrder
                ) {
                    $next = $candidate;
                }
            }

            if ($next === null) {
                throw new AttributeCompositionException(sprintf(
                    'Attribute ordering for %s contains a cycle.',
                    self::targetName($target),
                ));
            }

            $ordered[] = $usages[$next];
            unset($remaining[$next]);
            foreach (array_keys($edges[$next] ?? []) as $to) {
                --$indegree[$to];
            }
        }

        return $ordered;
    }

    /**
     * @param class-string $selector
     * @param list<AttributeUsage> $usages
     * @return list<AttributeUsage>
     */
    private function matching(string $selector, array $usages, ?AttributeUsage $except = null): array
    {
        $matches = [];
        foreach ($usages as $usage) {
            if ($usage === $except || !$this->matches($selector, $usage)) {
                continue;
            }
            $matches[] = $usage;
        }
        return $matches;
    }

    /**
     * @param class-string $selector
     * @param list<AttributeUsage> $usages
     * @return list<int>
     */
    private function matchingIndexes(string $selector, array $usages, int $except): array
    {
        $matches = [];
        foreach ($usages as $index => $usage) {
            if ($index !== $except && $this->matches($selector, $usage)) {
                $matches[] = $index;
            }
        }
        return $matches;
    }

    /** @param class-string $selector */
    private function matches(string $selector, AttributeUsage $usage): bool
    {
        if (is_a($selector, AttributeCapabilityInterface::class, true)) {
            foreach ($usage->definition->capabilities as $capability) {
                if (is_a($capability, $selector, true)) {
                    return true;
                }
            }
            return false;
        }

        return is_a($usage->attribute::class, $selector, true);
    }

    /**
     * @param array<int, array<int, true>> $edges
     * @param array<int, int> $indegree
     */
    private static function addEdge(array &$edges, array &$indegree, int $from, int $to): void
    {
        if ($from === $to || isset($edges[$from][$to])) {
            return;
        }
        $edges[$from][$to] = true;
        ++$indegree[$to];
    }

    private static function targetName(Reflector $target): string
    {
        return match (true) {
            $target instanceof ReflectionParameter => sprintf(
                '$%s of %s',
                $target->getName(),
                $target->getDeclaringClass() !== null
                    ? $target->getDeclaringClass()->getName() . '::' . $target->getDeclaringFunction()->getName() . '()'
                    : $target->getDeclaringFunction()->getName() . '()',
            ),
            $target instanceof ReflectionProperty => sprintf(
                '%s::$%s',
                $target->getDeclaringClass()->getName(),
                $target->getName(),
            ),
            $target instanceof ReflectionMethod => sprintf(
                '%s::%s()',
                $target->getDeclaringClass()->getName(),
                $target->getName(),
            ),
            $target instanceof ReflectionClass => $target->getName(),
            default => get_debug_type($target),
        };
    }

    private static function assertSupportedTarget(Reflector $target): void
    {
        if ($target instanceof ReflectionClass
            || $target instanceof ReflectionMethod
            || $target instanceof ReflectionParameter
            || $target instanceof ReflectionProperty
        ) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'Unsupported attribute target %s.',
            get_debug_type($target),
        ));
    }
}

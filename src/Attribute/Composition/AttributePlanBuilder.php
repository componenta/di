<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Composition;

use Componenta\DI\Exception\AttributeCompositionException;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;
use WeakMap;

/** Builds, validates, orders and memoizes the semantic attribute plan for one target. */
final class AttributePlanBuilder
{
    public const int FORMAT_VERSION = 2;

    /** @var array<string, AttributePlan> */
    private array $namedPlans = [];

    /** @var WeakMap<object, AttributePlan>|null */
    private ?WeakMap $anonymousPlans = null;

    private int $registryRevision = -1;

    public function __construct(private readonly AttributeDefinitionRegistry $registry) {}

    /** @param ReflectionClass<object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $target */
    public function build(
        ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target,
    ): AttributePlan {
        $this->synchronizeRegistryRevision();

        $key = self::cacheKey($target);
        if ($key !== null && isset($this->namedPlans[$key])) {
            return $this->namedPlans[$key];
        }
        if ($key === null) {
            $anonymous = $this->anonymousPlans ??= new WeakMap();
            if (isset($anonymous[$target])) {
                return $anonymous[$target];
            }
        }

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
        $this->assertCustomRules($usages);

        $plan = new AttributePlan($target, $this->ordered($target, $usages));
        if ($key !== null) {
            return $this->namedPlans[$key] = $plan;
        }

        $anonymous = $this->anonymousPlans ??= new WeakMap();
        $anonymous[$target] = $plan;
        return $plan;
    }

    private function synchronizeRegistryRevision(): void
    {
        if ($this->registryRevision === $this->registry->revision) {
            return;
        }

        $this->namedPlans = [];
        $this->anonymousPlans = new WeakMap();
        $this->registryRevision = $this->registry->revision;
    }

    /**
     * @param ReflectionClass<object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $target
     * @param list<AttributeUsage> $usages
     */
    private function assertCapabilityCardinality(
        ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target,
        array $usages,
    ): void {
        foreach ($this->registry->policies() as $policy) {
            if ($policy->maxPerTarget === null) {
                continue;
            }

            $members = [];
            foreach ($usages as $usage) {
                foreach ($usage->definition->capabilities as $capability) {
                    if (!is_a($capability, $policy->capability, true)) {
                        continue;
                    }

                    $members[] = $usage;
                    break;
                }
            }

            if (count($members) <= $policy->maxPerTarget) {
                continue;
            }

            throw new AttributeCompositionException(sprintf(
                '%s accepts at most %d attribute(s) with capability %s; found %s.',
                self::targetName($target),
                $policy->maxPerTarget,
                $policy->capability,
                implode(', ', array_map(
                    static fn(AttributeUsage $usage): string => '#[' . $usage->attribute::class . ']',
                    $members,
                )),
            ));
        }
    }

    /**
     * @param ReflectionClass<object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $target
     * @param list<AttributeUsage> $usages
     */
    private function assertDependencies(
        ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target,
        array $usages,
    ): void {
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
                if ($this->matching($selector, $usages, $usage) === []) {
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

    /** @param list<AttributeUsage> $usages */
    private function assertCustomRules(array $usages): void
    {
        if ($usages === []) {
            return;
        }

        $set = new AttributeSet($usages);
        foreach ($usages as $usage) {
            foreach ($usage->definition->rules as $rule) {
                $rule->validate($usage, $set);
            }
        }
    }

    /**
     * @param ReflectionClass<object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $target
     * @param list<AttributeUsage> $usages
     * @return list<AttributeUsage>
     */
    private function ordered(
        ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target,
        array $usages,
    ): array {
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
            if ($usage !== $except && $this->matches($selector, $usage)) {
                $matches[] = $usage;
            }
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

    /** @param ReflectionClass<object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $target */
    private static function cacheKey(
        ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target,
    ): ?string {
        return match (true) {
            $target instanceof ReflectionClass => 'class:' . $target->getName(),
            $target instanceof ReflectionProperty => sprintf(
                'property:%s::$%s',
                $target->getDeclaringClass()->getName(),
                $target->getName(),
            ),
            $target instanceof ReflectionMethod => sprintf(
                'method:%s::%s',
                $target->getDeclaringClass()->getName(),
                $target->getName(),
            ),
            default => self::parameterCacheKey($target),
        };
    }

    private static function parameterCacheKey(ReflectionParameter $parameter): ?string
    {
        $function = $parameter->getDeclaringFunction();
        if ($function instanceof ReflectionFunction && $function->isClosure()) {
            return null;
        }

        $owner = $parameter->getDeclaringClass()?->getName();
        return sprintf(
            'parameter:%s%s:%d',
            $owner === null ? '' : $owner . '::',
            $function->getName(),
            $parameter->getPosition(),
        );
    }

    /** @param ReflectionClass<object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $target */
    private static function targetName(
        ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target,
    ): string {
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
            default => $target->getName(),
        };
    }
}

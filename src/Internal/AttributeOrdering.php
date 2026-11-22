<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;
use SplMinHeap;

/**
 * Orders composed attributes and validates edges against their execution phases.
 *
 * @internal
 */
final class AttributeOrdering
{
    /**
     * @param ReflectionClass<covariant object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $target
     * @param list<AttributeUsage> $usages
     * @return list<AttributeUsage>
     */
    public function order(
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

        $this->assertPhaseOrder($target, $usages, $edges);

        if ($edges === []) {
            // Custom reflectors may return attribute indexes out of sequence.
            $inDeclarationOrder = true;
            for ($index = 1, $count = count($usages); $index < $count; ++$index) {
                if ($usages[$index - 1]->declarationOrder > $usages[$index]->declarationOrder) {
                    $inDeclarationOrder = false;
                    break;
                }
            }
            if ($inDeclarationOrder) {
                return $usages;
            }
        }

        /** @var SplMinHeap<array{int, int}> $ready */
        $ready = new SplMinHeap();
        foreach ($indegree as $index => $incoming) {
            if ($incoming === 0) {
                $ready->insert([$usages[$index]->declarationOrder, $index]);
            }
        }

        $ordered = [];
        while (!$ready->isEmpty()) {
            [, $next] = $ready->extract();
            $ordered[] = $usages[$next];
            foreach ($edges[$next] ?? [] as $to => $_) {
                if (--$indegree[$to] === 0) {
                    $ready->insert([$usages[$to]->declarationOrder, $to]);
                }
            }
        }

        if (count($ordered) !== count($usages)) {
            throw new AttributeCompositionException(sprintf(
                'Attribute ordering for %s contains a cycle.',
                describe_attribute_target($target),
            ));
        }

        return $ordered;
    }

    /**
     * @param ReflectionClass<covariant object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $target
     * @param list<AttributeUsage> $usages
     * @param array<int, array<int, true>> $edges
     */
    private function assertPhaseOrder(
        ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target,
        array $usages,
        array $edges,
    ): void {
        if ($target instanceof ReflectionParameter) {
            return;
        }

        foreach ($usages as $from => $before) {
            if (!$before->definition->handler instanceof AttributeHandlerInterface
                || $before->definition->phase !== AttributePhase::AfterInstantiation
            ) {
                continue;
            }

            $pending = array_keys($edges[$from] ?? []);
            $visited = [];
            while ($pending !== []) {
                $index = array_pop($pending);
                if (isset($visited[$index])) {
                    continue;
                }
                $visited[$index] = true;
                $after = $usages[$index];

                if ($after->definition->handler instanceof AttributeHandlerInterface) {
                    if ($after->definition->phase === AttributePhase::BeforeInstantiation) {
                        throw new AttributeCompositionException(sprintf(
                            'Attribute ordering for %s requires #[%s] before #[%s], which contradicts their execution phases.',
                            describe_attribute_target($target),
                            $before->attributeClass,
                            $after->attributeClass,
                        ));
                    }

                    // Both has separate invocations in each phase; only metadata is transparent here.
                    continue;
                }

                foreach (array_keys($edges[$index] ?? []) as $next) {
                    $pending[] = $next;
                }
            }
        }
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
            if ($index !== $except && $usage->matches($selector)) {
                $matches[] = $index;
            }
        }
        return $matches;
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
}

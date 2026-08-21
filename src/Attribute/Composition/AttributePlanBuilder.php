<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Composition;

use Attribute;
use Componenta\DI\Attribute\Composition\Capability\InvocationOnlyValueProvider;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Attribute\Composition\Capability\ValueTransformer;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;
use Throwable;

/** Builds, validates, orders and memoizes the semantic attribute plan for stable targets. */
final class AttributePlanBuilder
{
    public const int FORMAT_VERSION = 6;

    /** @var array<string, AttributePlan> */
    private array $namedPlans = [];
    /** @var array<class-string,int> */
    private array $attributeTargetFlags = [];
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

        $usages = [];
        foreach ($target->getAttributes() as $index => $reflectionAttribute) {
            /** @var class-string $attributeClass */
            $attributeClass = $reflectionAttribute->getName();
            $definition = $this->registry->definition($attributeClass);
            if ($definition === null) {
                continue;
            }

            if (!$this->supportsTarget($attributeClass, $target)) {
                if (self::isPromotedDuplicateTarget($target)) {
                    continue;
                }

                throw new AttributeCompositionException(sprintf(
                    'Attribute "%s" cannot target %s.',
                    $attributeClass,
                    self::targetKind($target),
                ));
            }

            $usages[] = new AttributeUsage(
                $this->instantiate(
                    $reflectionAttribute,
                    sprintf('attribute "%s" on %s', $attributeClass, self::targetName($target)),
                ),
                $definition,
                $target,
                $index,
            );
        }

        $this->assertCapabilityCardinality($target, $usages);
        $this->assertInvocationOnlyComposition($target, $usages);
        $this->assertReadonlyPropertyComposition($target, $usages);
        $this->assertParameterHandlerComposition($target, $usages);
        $this->assertDependencies($target, $usages);
        $this->assertCustomRules($usages);

        $plan = new AttributePlan($target, $this->ordered($target, $usages));
        if ($key === null) {
            // Closure parameters are intentionally not memoized. AttributePlan
            // retains its target reflector, and ReflectionParameter retains the
            // declaring Closure; caching such a plan would keep closure captures
            // alive after the public call has completed.
            return $plan;
        }

        return $this->namedPlans[$key] = $plan;
    }

    private function synchronizeRegistryRevision(): void
    {
        if ($this->registryRevision === $this->registry->revision) {
            return;
        }

        $this->namedPlans = [];
        $this->registryRevision = $this->registry->revision;
    }

    /**
     * @param class-string $attributeClass
     * @param ReflectionClass<object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $target
     */
    private function supportsTarget(
        string $attributeClass,
        ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target,
    ): bool {
        return ($this->attributeTargetFlags($attributeClass) & self::targetFlag($target)) !== 0;
    }

    /** @param class-string $attributeClass */
    private function attributeTargetFlags(string $attributeClass): int
    {
        if (isset($this->attributeTargetFlags[$attributeClass])) {
            return $this->attributeTargetFlags[$attributeClass];
        }

        try {
            $reflection = new ReflectionClass($attributeClass);
            $marker = $reflection->getAttributes(Attribute::class)[0] ?? null;
            if ($marker === null) {
                throw new AttributeCompositionException(sprintf(
                    'Registered DI attribute "%s" is not declared with #[Attribute].',
                    $attributeClass,
                ));
            }

            $metadata = $this->instantiate($marker, sprintf('#[Attribute] metadata for "%s"', $attributeClass));
            if (!$metadata instanceof Attribute) {
                throw new AttributeCompositionException(sprintf(
                    'Registered DI attribute "%s" has invalid #[Attribute] metadata.',
                    $attributeClass,
                ));
            }

            return $this->attributeTargetFlags[$attributeClass] = $metadata->flags;
        } catch (AttributeCompositionException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new AttributeCompositionException(
                sprintf('Cannot inspect registered DI attribute "%s": %s', $attributeClass, $e->getMessage()),
                previous: $e,
            );
        }
    }

    /**
     * @template T of object
     * @param ReflectionAttribute<T> $attribute
     * @return T
     */
    private function instantiate(ReflectionAttribute $attribute, string $context): object
    {
        try {
            return $attribute->newInstance();
        } catch (AttributeCompositionException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new AttributeCompositionException(
                sprintf('Cannot instantiate %s: %s', $context, $e->getMessage()),
                previous: $e,
            );
        }
    }

    /** @param ReflectionClass<object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $target */
    private static function targetFlag(
        ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target,
    ): int {
        return match (true) {
            $target instanceof ReflectionClass => Attribute::TARGET_CLASS,
            $target instanceof ReflectionProperty => Attribute::TARGET_PROPERTY,
            $target instanceof ReflectionMethod => Attribute::TARGET_METHOD,
            default => Attribute::TARGET_PARAMETER,
        };
    }

    /** @param ReflectionClass<object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $target */
    private static function isPromotedDuplicateTarget(
        ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target,
    ): bool {
        return ($target instanceof ReflectionProperty || $target instanceof ReflectionParameter)
            && $target->isPromoted();
    }

    /** @param ReflectionClass<object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $target */
    private static function targetKind(
        ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target,
    ): string {
        return match (true) {
            $target instanceof ReflectionClass => 'class',
            $target instanceof ReflectionProperty => 'property',
            $target instanceof ReflectionMethod => 'method',
            default => 'parameter',
        };
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
     * Invocation-only values belong to the current callable execution and must
     * not be captured by constructor-created object state.
     *
     * @param ReflectionClass<object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $target
     * @param list<AttributeUsage> $usages
     */
    private function assertInvocationOnlyComposition(
        ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target,
        array $usages,
    ): void {
        $invocationOnly = null;
        foreach ($usages as $usage) {
            foreach ($usage->definition->capabilities as $capability) {
                if (is_a($capability, InvocationOnlyValueProvider::class, true)) {
                    $invocationOnly = $usage;
                    break 2;
                }
            }
        }

        if ($invocationOnly === null) {
            return;
        }

        if (!$target instanceof ReflectionParameter) {
            throw new AttributeCompositionException(sprintf(
                '#[%s] is invocation-only and can target callable parameters only; got %s.',
                $invocationOnly->attribute::class,
                self::targetName($target),
            ));
        }

        $function = $target->getDeclaringFunction();
        if ($function instanceof ReflectionMethod && $function->isConstructor()) {
            throw new AttributeCompositionException(sprintf(
                '#[%s] is invocation-only and cannot target constructor parameter $%s of %s::__construct().',
                $invocationOnly->attribute::class,
                $target->getName(),
                $function->getDeclaringClass()->getName(),
            ));
        }
    }

    /**
     * Readonly properties can be written only once by the DI object pipeline.
     * A value source followed by a transformer requires two writes and therefore
     * cannot be represented safely by the property-handler contract.
     *
     * @param ReflectionClass<object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $target
     * @param list<AttributeUsage> $usages
     */
    private function assertReadonlyPropertyComposition(
        ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target,
        array $usages,
    ): void {
        if (!$target instanceof ReflectionProperty || !$target->isReadOnly()) {
            return;
        }

        $provider = false;
        $transformer = false;
        foreach ($usages as $usage) {
            foreach ($usage->definition->capabilities as $capability) {
                $provider = $provider || is_a($capability, ValueProvider::class, true);
                $transformer = $transformer || is_a($capability, ValueTransformer::class, true);
            }
        }

        if ($provider && $transformer) {
            throw new AttributeCompositionException(sprintf(
                '%s cannot combine a value source with a transformer because readonly properties can be written only once.',
                self::targetName($target),
            ));
        }
    }

    /**
     * A parameter may have one source-handler and any number of transformer
     * handlers. Multiple definitions may intentionally share one source-handler
     * (for example #[Make] + #[Proxy]).
     *
     * @param ReflectionClass<object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $target
     * @param list<AttributeUsage> $usages
     */
    private function assertParameterHandlerComposition(
        ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target,
        array $usages,
    ): void {
        if (!$target instanceof ReflectionParameter) {
            return;
        }

        /** @var array<int,ParameterAttributeHandlerInterface> $sources */
        $sources = [];
        foreach ($usages as $usage) {
            $handler = $usage->definition->handler;
            if (!$handler instanceof ParameterAttributeHandlerInterface) {
                continue;
            }

            $transformer = false;
            foreach ($usage->definition->capabilities as $capability) {
                if (is_a($capability, ValueTransformer::class, true)) {
                    $transformer = true;
                    break;
                }
            }
            if ($transformer) {
                continue;
            }

            $sources[spl_object_id($handler)] = $handler;
        }

        if (count($sources) <= 1) {
            return;
        }

        throw new AttributeCompositionException(sprintf(
            '%s resolves through multiple parameter source handlers: %s.',
            self::targetName($target),
            implode(', ', array_map(
                static fn(ParameterAttributeHandlerInterface $handler): string => $handler::class,
                array_values($sources),
            )),
        ));
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
                try {
                    $rule->validate($usage, $set);
                } catch (AttributeCompositionException $e) {
                    throw $e;
                } catch (Throwable $e) {
                    throw new AttributeCompositionException(
                        sprintf(
                            'Attribute composition rule "%s" failed for #[%s]: %s',
                            $rule::class,
                            $usage->attribute::class,
                            $e->getMessage(),
                        ),
                        previous: $e,
                    );
                }
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
                'method:%s::%s()',
                $target->getDeclaringClass()->getName(),
                $target->getName(),
            ),
            default => self::parameterCacheKey($target),
        };
    }

    private static function parameterCacheKey(ReflectionParameter $parameter): ?string
    {
        $function = $parameter->getDeclaringFunction();
        if ($function->isClosure()) {
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

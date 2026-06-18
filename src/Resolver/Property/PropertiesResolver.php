<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Property;

use Componenta\DI\Compile\PlanDispatcher;
use Componenta\DI\Compile\IndexedPlanProviderInterface;
use Componenta\DI\Compile\PlanProviderInterface;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\AttributeDrivenResolverInterface;
use Componenta\Stdlib\PriorityList;
use ReflectionProperty;

/**
 * Aggregates multiple property resolvers into a resolution chain.
 *
 * Resolvers are tried in priority order (higher priority first).
 * First resolver to return non-null wins.
 *
 * Unlike ParametersResolver, this resolver does NOT throw when a property
 * cannot be resolved; it simply skips it. This allows selective property
 * injection where only attributed properties are processed.
 *
 * Sub-resolvers take their dependencies (e.g. the container) through their
 * own constructors; this chain does not post-inject anything.
 */
class PropertiesResolver
{
    /** @var PriorityList<PropertyResolverInterface> */
    private PriorityList $items;

    private ?PlanDispatcher $planDispatcher = null;

    private ?PlanProviderInterface $planProvider = null;

    /**
     * Compiled per-property plans keyed by class and property name.
     * Only attribute-driven properties appear here.
     *
     * @var array<class-string, array<string, string|array{kind: string, payload: mixed}>>
     */
    private array $plans = [];

    /**
     * Returns a defensive clone of the internal resolver list so external
     * readers cannot mutate the chain.
     */
    public PriorityList $resolvers {
        get => clone $this->items;
    }

    public function __construct(PropertyResolverInterface ...$resolvers)
    {
        $this->items = new PriorityList();

        foreach ($resolvers as $resolver) {
            $this->items->insert($resolver);
        }
    }

    /**
     * Installs a compiled plan map. See {@see ParametersResolver::setCompiledPlans()}
     * for the analogous parameter-side hook.
     *
     * @param array<class-string, array<string, string|array{kind: string, payload: mixed}>> $plans
     */
    public function setCompiledPlans(array $plans, PlanDispatcher $dispatcher): void
    {
        $this->plans = $plans;
        $this->planDispatcher = $dispatcher;
        $this->planProvider = null;
    }

    public function setCompiledPlanProvider(PlanProviderInterface $provider, PlanDispatcher $dispatcher): void
    {
        $this->plans = [];
        $this->planProvider = $provider;
        $this->planDispatcher = $dispatcher;
    }

    /**
     * Adds a resolver with optional priority.
     *
     * Higher priority resolvers are tried first.
     */
    public function add(PropertyResolverInterface $resolver, int $priority = 0): void
    {
        $this->items->insert($resolver, $priority);
    }

    /**
     * Resolves all properties that can be resolved.
     *
     * Properties that cannot be resolved are skipped (not an error).
     *
     * @param ReflectionProperty[] $properties Properties to resolve.
     * @param array<string, mixed> $context Contextual data.
     * @return array<string, array{0: ReflectionProperty, 1: mixed}> Resolved values indexed by property name.
     *
     * @throws ResolutionException If a resolver fails during resolution.
     */
    public function resolve(array $properties, array $context = []): array
    {
        $resolved = [];

        foreach ($properties as $property) {
            $result = $this->resolveProperty($property, $context);
            if ($result !== null) {
                $resolved[$result[0]->getName()] = $result;
            }
        }

        return $resolved;
    }

    /**
     * Resolves a single property.
     *
     * @return array{0: ReflectionProperty, 1: mixed}|null Tuple of [property, value] or null.
     *
     * @throws ResolutionException
     */
    public function resolveProperty(
        ReflectionProperty $property,
        array $context = [],
    ): ?array {
        // Compiled plan fast-path. Property plans are sparse: only
        // attributed properties get an entry, so a missing kind here means
        // either the property is unattributed (chain would skip it anyway)
        // or the compiler couldn't classify it (chain handles the rest).
        if ($this->planDispatcher !== null) {
            $kind = $this->compiledPropertyPlan(
                $property->getDeclaringClass()->getName(),
                $property->getName(),
            );
            if ($kind !== null) {
                $result = $this->planDispatcher->dispatchProperty($kind, $property, $context);
                if ($result !== null) {
                    return $result;
                }
            }
        }

        // Same lazy-skip strategy as ParametersResolver: targets without
        // any attributes never pay for {@see AttributeDrivenResolverInterface}
        // resolver dispatch.
        $hasAttributes = null;

        foreach ($this->items as $resolver) {
            if ($resolver instanceof AttributeDrivenResolverInterface) {
                $hasAttributes ??= $property->getAttributes() !== [];
                if (!$hasAttributes) {
                    continue;
                }
            }

            $result = $resolver->resolveProperty($property, $context);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @return string|array{kind: string, payload: mixed}|null
     */
    private function compiledPropertyPlan(string $class, string $property): string|array|null
    {
        if ($this->planProvider instanceof IndexedPlanProviderInterface) {
            return $this->planProvider->propertyPlan($class, $property);
        }

        if ($this->planProvider !== null) {
            $plans = $this->planProvider->plans();
            $this->plans = is_array($plans['prop'] ?? null) ? $plans['prop'] : [];
            $this->planProvider = null;
        }

        return $this->plans[$class][$property] ?? null;
    }

    /**
     * Creates a resolver from an array of resolvers.
     *
     * @param PropertyResolverInterface[] $resolvers
     */
    public static function fromArray(array $resolvers): self
    {
        return new self(...$resolvers);
    }
}

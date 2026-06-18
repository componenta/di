<?php

declare(strict_types=1);

namespace Componenta\DI\Compile;

use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Property\PropertyResolverInterface;
use ReflectionParameter;
use ReflectionProperty;

/**
 * Maps a `planKind()` token to the resolver that produced it, then routes
 * the call directly - no chain walk, no per-call attribute scan.
 *
 * Both maps are keyed by the kind string (the same string the offline plan
 * stores) for an O(1) lookup. Missing entries return null; callers fall back
 * to the runtime chain. {@see bind()} auto-routes by interface, so passing
 * the same resolver to both sides is fine.
 *
 * Stateless once constructed.
 */
final class PlanDispatcher
{
    public const string CONFIG_KEY = 'di_plan_dispatcher';

    /** @var array<string, ParameterResolverInterface> */
    private array $paramByKind = [];

    /** @var array<string, PropertyResolverInterface> */
    private array $propByKind = [];

    /**
     * Builds the cacheable `kind -> resolver class` map from live resolver
     * chains. Duplicate kinds intentionally keep the last binding to match
     * {@see bind()} semantics.
     *
     * @return array{
     *     param: array<string, class-string<ParameterResolverInterface>>,
     *     prop: array<string, class-string<PropertyResolverInterface>>
     * }
     */
    public static function kindMap(iterable $paramResolvers, iterable $propResolvers): array
    {
        $map = ['param' => [], 'prop' => []];

        foreach ($paramResolvers as $resolver) {
            if ($resolver instanceof AttributeMatcherInterface
                && $resolver instanceof ParameterResolverInterface
            ) {
                $map['param'][$resolver->planKind()] = $resolver::class;
            }
        }

        foreach ($propResolvers as $resolver) {
            if ($resolver instanceof AttributeMatcherInterface
                && $resolver instanceof PropertyResolverInterface
            ) {
                $map['prop'][$resolver->planKind()] = $resolver::class;
            }
        }

        return $map;
    }

    /**
     * Rehydrates a dispatcher from a cached kind map. Returns null when the
     * map references a resolver class that is not present in the runtime
     * chains, allowing callers to fall back to the old scan-and-bind path.
     *
     * @param array{
     *     param?: array<string, class-string>,
     *     prop?: array<string, class-string>
     * } $map
     */
    public static function fromKindMap(
        array $map,
        iterable $paramResolvers,
        iterable $propResolvers,
    ): ?self {
        $dispatcher = new self();

        if (!$dispatcher->bindParameterMap($map['param'] ?? [], $paramResolvers)) {
            return null;
        }

        if (!$dispatcher->bindPropertyMap($map['prop'] ?? [], $propResolvers)) {
            return null;
        }

        return $dispatcher;
    }

    /**
     * Registers a matcher-aware resolver under its own {@see AttributeMatcherInterface::planKind()}
     * token. Bound to the parameter side, the property side, or both -
     * whichever interfaces it implements.
     */
    public function bind(AttributeMatcherInterface $resolver): void
    {
        $kind = $resolver->planKind();

        if ($resolver instanceof ParameterResolverInterface) {
            $this->bindParameterKind($kind, $resolver);
        }

        if ($resolver instanceof PropertyResolverInterface) {
            $this->bindPropertyKind($kind, $resolver);
        }
    }

    public function bindParameterKind(string $kind, ParameterResolverInterface $resolver): void
    {
        $this->paramByKind[$kind] = $resolver;
    }

    public function bindPropertyKind(string $kind, PropertyResolverInterface $resolver): void
    {
        $this->propByKind[$kind] = $resolver;
    }

    /**
     * Dispatch a parameter plan entry. Returns the resolver tuple
     * `[position, value]`, or null when the plan kind is unknown to this
     * dispatcher (caller should fall back to the runtime chain) - or when
     * the resolver itself returned null.
     *
     * @param string|array{kind?: string, payload?: mixed} $entry
     * @param array<string|int, mixed> $providedParameters
     * @param array<int, mixed>        $resolvedParameters
     * @return array{0: int, 1: mixed}|null
     */
    public function dispatchParameter(
        string|array $entry,
        ReflectionParameter $parameter,
        array $providedParameters,
        array $resolvedParameters,
    ): ?array {
        $plan = $this->normalizeEntry($entry);
        if ($plan === null) {
            return null;
        }

        [$kind, $hasPayload, $payload] = $plan;
        $resolver = $this->paramByKind[$kind] ?? null;

        if ($resolver === null) {
            return null;
        }

        if ($hasPayload && $resolver instanceof ParameterPlanResolverInterface) {
            return $resolver->resolveParameterPlan(
                $parameter,
                $payload,
                $providedParameters,
                $resolvedParameters,
            );
        }

        return $resolver->resolveParameter($parameter, $providedParameters, $resolvedParameters);
    }

    /**
     * @param string|array{kind?: string, payload?: mixed} $entry
     * @param array<string, mixed> $context
     * @return array{0: ReflectionProperty, 1: mixed}|null
     */
    public function dispatchProperty(
        string|array $entry,
        ReflectionProperty $property,
        array $context,
    ): ?array {
        $plan = $this->normalizeEntry($entry);
        if ($plan === null) {
            return null;
        }

        [$kind, $hasPayload, $payload] = $plan;
        $resolver = $this->propByKind[$kind] ?? null;

        if ($resolver === null) {
            return null;
        }

        if ($hasPayload && $resolver instanceof PropertyPlanResolverInterface) {
            return $resolver->resolvePropertyPlan($property, $payload, $context);
        }

        return $resolver->resolveProperty($property, $context);
    }

    public function hasParameterKind(string $kind): bool
    {
        return isset($this->paramByKind[$kind]);
    }

    public function hasPropertyKind(string $kind): bool
    {
        return isset($this->propByKind[$kind]);
    }

    /**
     * @param string|array{kind?: string, payload?: mixed} $entry
     * @return array{0: string, 1: bool, 2: mixed}|null
     */
    private function normalizeEntry(string|array $entry): ?array
    {
        if (is_string($entry)) {
            return [$entry, false, null];
        }

        if (!isset($entry['kind']) || !is_string($entry['kind'])) {
            return null;
        }

        return [$entry['kind'], array_key_exists('payload', $entry), $entry['payload'] ?? null];
    }

    /**
     * @param array<string, class-string> $map
     */
    private function bindParameterMap(array $map, iterable $resolvers): bool
    {
        if ($map === []) {
            return true;
        }

        $pending = $map;

        foreach ($resolvers as $resolver) {
            if (!$resolver instanceof AttributeMatcherInterface
                || !$resolver instanceof ParameterResolverInterface
            ) {
                continue;
            }

            foreach ($map as $kind => $class) {
                if ($resolver::class === $class && $resolver->planKind() === $kind) {
                    $this->bindParameterKind($kind, $resolver);
                    unset($pending[$kind]);
                }
            }
        }

        return $pending === [];
    }

    /**
     * @param array<string, class-string> $map
     */
    private function bindPropertyMap(array $map, iterable $resolvers): bool
    {
        if ($map === []) {
            return true;
        }

        $pending = $map;

        foreach ($resolvers as $resolver) {
            if (!$resolver instanceof AttributeMatcherInterface
                || !$resolver instanceof PropertyResolverInterface
            ) {
                continue;
            }

            foreach ($map as $kind => $class) {
                if ($resolver::class === $class && $resolver->planKind() === $kind) {
                    $this->bindPropertyKind($kind, $resolver);
                    unset($pending[$kind]);
                }
            }
        }

        return $pending === [];
    }
}

<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

use Componenta\DI\Compile\PlanDispatcher;
use Componenta\DI\Compile\IndexedPlanProviderInterface;
use Componenta\DI\Compile\PlanProviderInterface;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\AttributeDrivenResolverInterface;
use Componenta\Stdlib\PriorityList;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;

/**
 * Aggregates multiple parameter resolvers into a resolution chain.
 *
 * Resolvers are tried in priority order (higher priority first).
 * First resolver to return non-null wins.
 *
 * Sub-resolvers take their dependencies (e.g. the container) through their
 * own constructors; this chain does not post-inject anything.
 */
class ParametersResolver
{
    /** @var PriorityList<ParameterResolverInterface> */
    private PriorityList $items;

    private ?PlanDispatcher $planDispatcher = null;

    private ?PlanProviderInterface $planProvider = null;

    /**
     * Compiled per-parameter plans keyed by class, method and position.
     *
     * @var array<class-string, array<string, array<int, string|array{kind: string, payload: mixed}>>>
     */
    private array $plans = [];

    /**
     * Returns a defensive clone of the internal resolver list so external
     * readers cannot mutate the chain.
     */
    public PriorityList $resolvers {
        get => clone $this->items;
    }

    public function __construct(ParameterResolverInterface ...$resolvers)
    {
        $this->items = new PriorityList();

        foreach ($resolvers as $resolver) {
            $this->items->insert($resolver);
        }
    }

    /**
     * Installs a compiled plan map and the dispatcher that knows how to
     * execute it. Both arguments come from the same source (the offline
     * compile step) and are therefore set together.
     *
     * @param array<class-string, array<string, array<int, string|array{kind: string, payload: mixed}>>> $plans
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
    public function add(ParameterResolverInterface $resolver, int $priority = 0): void
    {
        $this->items->insert($resolver, $priority);
    }

    /**
     * Resolves all parameters.
     *
     * @param ReflectionParameter[] $parameters Parameters to resolve.
     * @param array<string|int, mixed> $providedParameters User-provided values.
     * @return array<int, mixed> Resolved values indexed by position.
     *
     * @throws ResolutionException If any parameter cannot be resolved.
     */
    public function resolve(array $parameters, array $providedParameters = []): array
    {
        $resolved = [];

        foreach ($parameters as $parameter) {
            [$position, $value] = $this->resolveParameter($parameter, $providedParameters, $resolved);
            $resolved[$position] = $value;
        }

        return $resolved;
    }

    /**
     * Resolves a single parameter.
     *
     * @return array{0: int, 1: mixed} Tuple of [position, value].
     *
     * @throws ResolutionException If parameter cannot be resolved.
     */
    public function resolveParameter(
        ReflectionParameter $parameter,
        array $providedParameters = [],
        array $resolvedParameters = [],
    ): array {
        // Compiled plan fast-path: when the offline compiler has decided
        // which resolver wins for this parameter AND no caller-supplied
        // override targets this specific parameter (which could otherwise
        // change the answer via ParameterArrayResolver / ArrayTypedResolver),
        // dispatch straight to the planned resolver and skip the chain walk.
        if ($this->planDispatcher !== null && !$this->providedAffects($parameter, $providedParameters)) {
            $declaringClass = $parameter->getDeclaringClass()?->getName();
            $methodPlan = $declaringClass !== null
                ? $this->compiledParameterPlan($declaringClass, $parameter->getDeclaringFunction()->getName())
                : null;
            $kind = $methodPlan[$parameter->getPosition()] ?? null;

            if ($kind !== null) {
                $result = $this->planDispatcher->dispatchParameter($kind, $parameter, $providedParameters, $resolvedParameters);
                if ($result !== null) {
                    return $result;
                }
                // Plan didn't produce a value (e.g. autowire target absent
                // at runtime); fall through to the chain so the proper
                // tail resolvers (default/nullable) get a chance.
            }
        }

        // Lazily computed: only `getAttributes()` once we hit the first
        // attribute-driven resolver in the chain. Targets without any
        // attributes skip every {@see AttributeDrivenResolverInterface}
        // resolver outright: no ParameterTarget allocation, no WeakMap
        // metadata lookup.
        $hasAttributes = null;

        foreach ($this->items as $resolver) {
            if ($resolver instanceof AttributeDrivenResolverInterface) {
                $hasAttributes ??= $parameter->getAttributes() !== [];
                if (!$hasAttributes) {
                    continue;
                }
            }

            $result = $resolver->resolveParameter($parameter, $providedParameters, $resolvedParameters);

            if ($result !== null) {
                return $result;
            }
        }

        throw ResolutionException::forParameter(
            $parameter,
            providedParameters: $providedParameters,
            resolvedParameters: $resolvedParameters,
        );
    }

    /**
     * @return array<int, string|array{kind: string, payload: mixed}>|null
     */
    private function compiledParameterPlan(string $class, string $method): ?array
    {
        if ($this->planProvider instanceof IndexedPlanProviderInterface) {
            return $this->planProvider->parameterPlan($class, $method);
        }

        if ($this->planProvider !== null) {
            $plans = $this->planProvider->plans();
            $this->plans = is_array($plans['param'] ?? null) ? $plans['param'] : [];
            $this->planProvider = null;
        }

        return $this->plans[$class][$method] ?? null;
    }

    /**
     * Whether `$providedParameters` carries an override that could satisfy
     * this parameter via the chain: an entry keyed by position, name,
     * or a type that the parameter is assignable to (exact class, parent,
     * or interface), including object values that match by `instanceof`.
     * Mirrors the lookups that `ParameterArrayResolver` /
     * `ArrayTypedResolver` would perform.
     *
     * Used to decide whether the plan fast-path is safe. When the caller
     * passes an override that specifically targets this parameter, we must
     * walk the chain so `ParameterArrayResolver` wins; when they just drop
     * an unrelated value into the array, the plan still applies.
     *
     * @param array<int|string, mixed> $providedParameters
     */
    private function providedAffects(ReflectionParameter $parameter, array $providedParameters): bool
    {
        if ($providedParameters === []) {
            return false;
        }

        if (array_key_exists($parameter->getPosition(), $providedParameters)) {
            return true;
        }

        if (array_key_exists($parameter->getName(), $providedParameters)) {
            return true;
        }

        $typeNames = $this->providedTypeNames($parameter);

        if ($typeNames === []) {
            return false;
        }

        foreach ($typeNames as $typeName) {
            // Exact class-name hit is the common case (e.g. ServerRequestInterface
            // keyed context from `InterceptedRouteHandlerMiddleware`).
            if (array_key_exists($typeName, $providedParameters)) {
                return true;
            }
        }

        // Widen to assignment-compatible keys so an override keyed by a
        // parent class or interface still wins over the compiled plan when
        // the parameter is its subtype. `is_a(child, parent, true)` allows
        // the string-form check without autoloading the parent.
        foreach ($providedParameters as $key => $_) {
            if (!is_string($key)) {
                continue;
            }

            foreach ($typeNames as $typeName) {
                if (is_a($typeName, $key, allow_string: true)) {
                    return true;
                }
            }
        }

        // ArrayTypedResolver also accepts unkeyed/foreign-keyed object values
        // when they satisfy the declared type. Mirror that rule so runtime
        // overrides keep winning over compiled autowire plans.
        foreach ($providedParameters as $value) {
            if (!is_object($value)) {
                continue;
            }

            foreach ($typeNames as $typeName) {
                if (is_a($value, $typeName)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<class-string>
     */
    private function providedTypeNames(ReflectionParameter $parameter): array
    {
        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType) {
            return $type->isBuiltin() ? [] : [$type->getName()];
        }

        if (!$type instanceof ReflectionUnionType) {
            return [];
        }

        $names = [];

        foreach ($type->getTypes() as $unionType) {
            if ($unionType instanceof ReflectionNamedType && !$unionType->isBuiltin()) {
                $names[] = $unionType->getName();
            }
        }

        return $names;
    }

    /**
     * Creates a resolver from an array of resolvers.
     *
     * @param ParameterResolverInterface[] $resolvers
     */
    public static function fromArray(array $resolvers): self
    {
        return new self(...$resolvers);
    }
}

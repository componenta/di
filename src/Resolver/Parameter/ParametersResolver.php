<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Exception\ExceptionInterface;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Internal\BootstrapResolutionGuard;
use Componenta\DI\Internal\CompletedAttributeGuard;
use Componenta\DI\Internal\Resolver\Parameter\ParameterResolutionBoundary;
use Componenta\DI\Internal\Resolver\Parameter\PreparedParameter;
use Componenta\DI\Internal\Resolver\Parameter\PreparedParameterPlan;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Resolver\Target\ParameterTargetFactory;
use ReflectionParameter;
use Throwable;
use WeakMap;

use function Componenta\DI\Internal\is_closure_reflector;

/** Orchestrates the ordered ParameterResolverInterface chain. */
final class ParametersResolver
{
    /** @var list<array{resolver:ParameterResolverInterface,priority:int,order:int}> */
    private array $registrations = [];
    /** @var list<array{resolver:ParameterResolverInterface,priority:int,order:int}>|null */
    private ?array $orderedRegistrations = null;
    /** @var list<ParameterResolverInterface>|null */
    private ?array $ordered = null;
    /** @var array<int,true> */
    private array $registered = [];
    /** @var WeakMap<ParameterTarget,PreparedParameter>|null */
    private ?WeakMap $preparedParameters = null;

    private int $revision = 0;
    private int $attributeRevision = -1;
    private int $order = 0;
    private bool $sealed = false;
    private ParameterTargetFactory $targetFactory;
    private readonly object $planOwner;

    public function __construct(
        private readonly AttributePlanBuilder $plans,
        ?ParameterTargetFactory $targetFactory = null,
        private readonly ?BootstrapResolutionGuard $bootstrap = null,
    ) {
        $this->targetFactory = $targetFactory ?? new ParameterTargetFactory();
        $this->planOwner = new \stdClass();
    }

    public bool $isSealed {
        get => $this->sealed;
    }

    /** @internal */
    public bool $canCachePlans {
        get => $this->sealed && $this->plans->canCachePlans;
    }

    /** Higher priorities run first; equal priorities preserve insertion order. */
    public function add(ParameterResolverInterface $resolver, int $priority = 0): void
    {
        if ($this->sealed) {
            throw new InvalidConfigurationException(
                'Parameter resolver pipeline is sealed and cannot be changed.',
            );
        }

        $objectId = spl_object_id($resolver);
        if (isset($this->registered[$objectId])) {
            throw new InvalidConfigurationException(sprintf(
                'Parameter resolver %s is already registered.',
                $resolver::class,
            ));
        }

        $this->registrations[] = [
            'resolver' => $resolver,
            'priority' => $priority,
            'order' => $this->order++,
        ];
        $this->registered[$objectId] = true;
        $this->orderedRegistrations = null;
        $this->ordered = null;
        $this->preparedParameters = null;
        ++$this->revision;
    }

    public function seal(): void
    {
        $this->registered = [];
        $this->preparedParameters = null;
        $this->sealed = true;
    }

    /** @var list<ParameterResolverInterface> */
    public array $resolverList {
        get => $this->ordered ??= array_map(
            static fn(array $registration): ParameterResolverInterface => $registration['resolver'],
            $this->registrationsInOrder(),
        );
    }

    /** @return list<array{resolver:ParameterResolverInterface,priority:int}> */
    public function semanticRegistrations(): array
    {
        return array_map(
            static fn(array $registration): array => [
                'resolver' => $registration['resolver'],
                'priority' => $registration['priority'],
            ],
            $this->registrationsInOrder(),
        );
    }

    /**
     * @param list<ReflectionParameter> $parameters
     * @param array<string|int,mixed> $providedParameters
     * @return array<array-key,mixed>
     */
    public function resolve(array $parameters, array $providedParameters = []): array
    {
        return $this->resolveTargets($this->targets($parameters), $providedParameters);
    }

    /**
     * @param list<ReflectionParameter> $parameters
     * @return list<ParameterTarget>
     */
    public function targets(array $parameters): array
    {
        $targets = [];
        foreach ($parameters as $parameter) {
            $targets[] = $this->target($parameter);
        }
        return $targets;
    }

    /**
     * @internal
     * @param list<ReflectionParameter> $parameters
     */
    public function prepare(array $parameters): PreparedParameterPlan
    {
        return $this->prepareTargets($this->targets($parameters));
    }

    /**
     * @internal
     * @param list<ParameterTarget> $targets
     */
    public function prepareTargets(array $targets): PreparedParameterPlan
    {
        $this->synchronizeAttributeRevision();
        $revision = $this->revision;
        $prepared = [];
        foreach ($targets as $target) {
            $prepared[] = $this->prepareTarget($target);
        }

        return new PreparedParameterPlan(
            $prepared,
            $targets,
            $revision,
            $this->planOwner,
        );
    }

    /**
     * @param list<ParameterTarget> $targets
     * @param array<string|int,mixed> $providedParameters
     * @return array<array-key,mixed>
     */
    public function resolveTargets(array $targets, array $providedParameters = []): array
    {
        return $this->resolvePrepared(
            $this->prepareTargets($targets),
            $providedParameters,
        );
    }

    /**
     * Resolves a prepared plan while keeping DI-owned metadata outside the public context.
     *
     * @internal
     * @param array<string|int,mixed> $providedParameters
     * @return array<array-key,mixed>
     */
    public function resolvePrepared(
        PreparedParameterPlan $plan,
        array $providedParameters = [],
    ): array {
        $plan = $this->refreshPlan($plan);
        $state = new ParameterResolutionContext(
            ParameterResolutionBoundary::publicParameters($plan, $providedParameters),
        );

        $completed = new CompletedAttributeGuard($this->plans, $this->plans->revision);

        $arguments = [];
        $omitted = false;
        foreach (array_keys($plan->parameters) as $index) {
            $result = $this->resolvePreparedParameter($plan->parameters[$index], $state, $completed, allowOmission: true);

            // Dependencies and handlers can load source attributes, including on the current parameter.
            $current = $this->refreshPlan($plan);
            if ($current !== $plan) {
                $plan = $current;
                ParameterResolutionBoundary::publicParameters($plan, $providedParameters);
                $completed->assertUnchanged();
            }

            if ($result === null) {
                $omitted = true;
                continue;
            }

            [$position, $value] = $result;
            $target = $plan->parameters[$index]->target;
            $state->resolve($position, $value);
            // Resolver state retains one value per declaration; only the native
            // invocation vector expands a variadic collection.
            if ($target->variadic && is_array($value)) {
                if ($omitted && array_any(array_keys($value), static fn(string|int $key): bool => is_int($key))) {
                    throw ResolutionException::forParameter(
                        $target->reflection,
                        reason: 'Positional variadic arguments cannot follow omitted native arguments.',
                    );
                }
                $arguments = [...$arguments, ...$value];
            } elseif ($omitted) {
                $arguments[$target->name] = $value;
            } else {
                $arguments[] = $value;
            }
        }

        $completed->assertUnchanged();
        return $arguments;
    }

    /** @internal */
    public function isCurrentPlan(PreparedParameterPlan $plan): bool
    {
        $this->synchronizeAttributeRevision();

        return $this->canCachePlans
            && $plan->owner === $this->planOwner
            && $plan->resolverRevision === $this->revision;
    }

    /** @return array{0:int,1:mixed} */
    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): array {
        $completed = new CompletedAttributeGuard($this->plans, $this->plans->revision);
        $result = $this->resolvePreparedParameter($this->prepareTarget($target), $context, $completed);
        $completed->assertUnchanged();
        return $result;
    }

    /** @return list<int> */
    public function resolverSlotsFor(ParameterTarget $target): array
    {
        return $this->prepareTarget($target)->resolverSlots;
    }

    public function target(ReflectionParameter $parameter): ParameterTarget
    {
        return $this->targetFactory->create($parameter);
    }

    /** @return ($allowOmission is true ? array{0:int,1:mixed}|null : array{0:int,1:mixed}) */
    private function resolvePreparedParameter(
        PreparedParameter $prepared,
        ParameterResolutionContext $context,
        CompletedAttributeGuard $completed,
        bool $allowOmission = false,
    ): ?array {
        $target = $prepared->target;
        $nativeOptional = $allowOmission
            && !$target->variadic
            && $target->reflection->isOptional()
            && $target->reflection->getDeclaringFunction()->isInternal();
        $attributePlan = $this->plans->build($target->reflection);

        try {
            $resolvers = $this->resolverList;
            foreach ($prepared->resolverSlots as $slot) {
                $resolver = $resolvers[$slot];
                $result = $resolver->resolveParameter($target, $context);
                if ($resolver instanceof AttributeParameterResolver) {
                    // The attribute bridge refreshes and consumes its plan during resolution.
                    // Other resolvers do not execute newly available attribute handlers.
                    $attributePlan = $this->plans->build($target->reflection);
                }
                if ($result !== null) {
                    $result = \Componenta\DI\Internal\validate_parameter_resolution_result(
                        $result,
                        $resolver,
                        $target,
                        $context,
                    );
                    $completed->record($target->reflection, $attributePlan);
                    if ($this->bootstrap?->isActive === true) {
                        $this->bootstrap->recordAttributes($target->reflection, plan: $attributePlan);
                        $prefix = array_slice($resolvers, 0, $slot);
                        $prefix[] = $resolver;
                        $this->bootstrap->recordParameter($target, $prefix);
                    }
                    // Native defaults may depend on omission (e.g. array_keys()).
                    // Keep explicit and extension-provided values, but let PHP
                    // supply defaults instead of synthesizing argument values.
                    return $nativeOptional && ($resolver instanceof DefaultValueResolver || $resolver instanceof NullableResolver)
                        ? null
                        : $result;
                }
            }
            if ($nativeOptional) {
                $completed->record($target->reflection, $attributePlan);
                if ($this->bootstrap?->isActive === true) {
                    $this->bootstrap->recordAttributes($target->reflection, plan: $attributePlan);
                    $this->bootstrap->recordParameter($target, $resolvers, resolved: false);
                }
                return null;
            }
        } catch (ExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forParameter(
                $target->reflection,
                previous: $e,
                providedParameters: $context->provided,
                resolvedParameters: $context->resolved,
            );
        }

        throw ResolutionException::forParameter(
            $target->reflection,
            providedParameters: $context->provided,
            resolvedParameters: $context->resolved,
        );
    }

    private function refreshPlan(PreparedParameterPlan $plan): PreparedParameterPlan
    {
        if ($plan->owner !== $this->planOwner) {
            throw new InvalidConfigurationException(
                'Prepared parameter plan belongs to another resolver pipeline.',
            );
        }

        $this->synchronizeAttributeRevision();

        if ($this->plans->canCachePlans && $plan->resolverRevision === $this->revision) {
            return $plan;
        }

        return $this->prepareTargets($plan->targets);
    }

    private function prepareTarget(ParameterTarget $target): PreparedParameter
    {
        $this->synchronizeAttributeRevision();

        $cacheable = $this->canCachePlans && self::isStableTarget($target);
        if ($cacheable) {
            $cache = $this->preparedParameters ??= new WeakMap();
            if (isset($cache[$target])) {
                return $cache[$target];
            }
        }

        $unsupportedReason = match (true) {
            $target->byReference => 'By-reference parameters are not supported by the DI resolver contract.',
            default => null,
        };

        if ($unsupportedReason !== null) {
            throw ResolutionException::forParameter(
                $target->reflection,
                reason: $unsupportedReason,
            );
        }

        $this->plans->build($target->reflection);
        $prepared = new PreparedParameter(
            $target,
            $this->classifyResolverSlots($target),
        );

        if (!$cacheable) {
            return $prepared;
        }

        $cache = $this->preparedParameters ??= new WeakMap();
        return $cache[$target] = $prepared;
    }

    private function synchronizeAttributeRevision(): void
    {
        $revision = $this->plans->revision;
        if ($revision === $this->attributeRevision) {
            return;
        }

        $this->attributeRevision = $revision;
        $this->preparedParameters = null;
        ++$this->revision;
    }

    private static function isStableTarget(ParameterTarget $target): bool
    {
        $function = $target->reflection->getDeclaringFunction();

        // PreparedParameter retains its target; closure-owned targets must not
        // be shared by name or kept alive through this cache.
        return !is_closure_reflector($function);
    }

    /** @return list<int> */
    private function classifyResolverSlots(ParameterTarget $target): array
    {
        /** @var list<int> $slots */
        $slots = [];
        $revision = $this->revision;

        try {
            foreach ($this->resolverList as $slot => $resolver) {
                if ($resolver->supports($target)) {
                    $slots[] = $slot;
                }
            }
        } catch (ExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forParameter(
                $target->reflection,
                reason: 'parameter resolver classification failed',
                previous: $e,
            );
        }

        if ($revision !== $this->revision) {
            throw new InvalidConfigurationException(
                'Parameter resolver supports() must not mutate the resolver chain.',
            );
        }

        return $slots;
    }

    /** @return list<array{resolver:ParameterResolverInterface,priority:int,order:int}> */
    private function registrationsInOrder(): array
    {
        if ($this->orderedRegistrations !== null) {
            return $this->orderedRegistrations;
        }

        $registrations = $this->registrations;
        usort(
            $registrations,
            static fn(array $left, array $right): int =>
                $right['priority'] <=> $left['priority']
                ?: $left['order'] <=> $right['order'],
        );

        return $this->orderedRegistrations = $registrations;
    }
}

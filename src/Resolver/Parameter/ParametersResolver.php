<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

use Closure;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Exception\ExceptionInterface;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Internal\BootstrapResolutionGuard;
use Componenta\DI\Internal\CompletedAttributeGuard;
use Componenta\DI\Internal\Resolver\Parameter\ParameterResolutionBoundary;
use Componenta\DI\Internal\Resolver\Parameter\PreparedParameterPlan;
use Componenta\DI\Internal\Resolver\Parameter\Request\MappedRequestContext;
use Componenta\DI\Internal\Resolver\Parameter\Request\MappedRequestParameterSourceGuard;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Resolver\Target\ParameterTargetFactory;
use ReflectionParameter;
use Throwable;
use WeakMap;

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
    /** @var WeakMap<ParameterTarget,list<int>>|null */
    private ?WeakMap $preparedSlots = null;
    /** @var WeakMap<ParameterTarget,list<string>>|null */
    private ?WeakMap $pendingAttributeQueries = null;

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
        get => $this->sealed;
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
        $this->preparedSlots = null;
        $this->pendingAttributeQueries = null;
        ++$this->revision;
    }

    public function seal(): void
    {
        $this->registered = [];
        $this->preparedSlots = null;
        $this->pendingAttributeQueries = null;
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
    public function prepare(array $parameters, ?Closure $closure = null): PreparedParameterPlan
    {
        $targets = $this->targets($parameters);
        if ($closure !== null && $this->bootstrap?->isActive === true) {
            $this->bootstrap->recordClosureOwner($targets, $closure);
        }
        return $this->prepareTargets($targets);
    }

    /**
     * @internal
     * @param list<ParameterTarget> $targets
     */
    public function prepareTargets(array $targets): PreparedParameterPlan
    {
        $this->synchronizeAttributeRevision();
        $revision = $this->revision;
        $selected = [];
        $requiresFreshValidation = false;
        foreach ($targets as $target) {
            // Classification can run application autoloaders. Keep the target
            // selected for this slot even if the caller's reference is rebound.
            $selected[] = $target;
            $this->prepareTarget($target, $targetRequiresFreshValidation);
            $requiresFreshValidation = $requiresFreshValidation || $targetRequiresFreshValidation;
        }

        return new PreparedParameterPlan(
            $selected,
            $revision,
            $this->planOwner,
            $requiresFreshValidation,
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
        return $this->resolvePreparedInvocation($plan, $providedParameters);
    }

    /**
     * @internal
     * @param array<string|int,mixed> $providedParameters
     * @return array<array-key,mixed>
     */
    public function resolveTracked(
        PreparedParameterPlan $plan,
        array $providedParameters,
        ?CompletedAttributeGuard &$proof,
    ): array {
        return $this->resolvePreparedInvocation($plan, $providedParameters, proof: $proof, retainProof: true);
    }

    /**
     * @internal
     * @param array<string|int,mixed> $providedParameters
     * @param class-string|null $diagnosticOwner
     * @param (Closure():void)|null $beforeInvocation
     * @return array<string|int,mixed>
     */
    public function resolvePreparedSuffix(
        PreparedParameterPlan $suffix,
        ParameterTarget $firstTarget,
        mixed $firstArgument,
        array $providedParameters = [],
        ?string $diagnosticOwner = null,
        ?Closure $beforeInvocation = null,
    ): array {
        if ($firstTarget->position !== 0) {
            throw new InvalidConfigurationException('The semantic callback argument must be its first declaration.');
        }

        return $this->resolvePreparedInvocation(
            $suffix,
            $providedParameters,
            [$firstArgument],
            $firstTarget,
            $diagnosticOwner,
            $beforeInvocation,
        );
    }

    /**
     * @param array<string|int,mixed> $providedParameters
     * @param list<mixed> $arguments
     * @param class-string|null $diagnosticOwner
     * @param (Closure():void)|null $beforeInvocation
     * @return array<string|int,mixed>
     */
    private function resolvePreparedInvocation(
        PreparedParameterPlan $plan,
        array $providedParameters,
        array $arguments = [],
        ?ParameterTarget $reservedTarget = null,
        ?string $diagnosticOwner = null,
        ?Closure $beforeInvocation = null,
        ?CompletedAttributeGuard &$proof = null,
        bool $retainProof = false,
    ): array {
        if (!$plan->belongsTo($this->planOwner)) {
            throw new InvalidConfigurationException(
                'Prepared parameter plan belongs to another resolver pipeline.',
            );
        }

        $plan = $this->refreshPlan($plan);
        $state = new ParameterResolutionContext(
            ParameterResolutionBoundary::publicParameters($plan, $providedParameters, $diagnosticOwner),
            $arguments,
        );
        $provenance = MappedRequestContext::get($providedParameters);
        $targets = $provenance === null ? [] : MappedRequestParameterSourceGuard::potentialSourceTargets($plan->targets, $provenance);
        $beforeConsumption = $targets === [] && $beforeInvocation === null ? null : static function () use ($targets, $provenance, $diagnosticOwner, $beforeInvocation): void {
            $beforeInvocation?->__invoke();
            if ($provenance !== null) {
                // Each extension receives the entire public context, including
                // mapped values belonging to other declarations in this call.
                MappedRequestParameterSourceGuard::assertTargetsProvenanceNoConflicts(
                    $targets,
                    $provenance,
                    $diagnosticOwner,
                    autoload: false,
                );
            }
        };

        $completed = new CompletedAttributeGuard($this->plans, $this->plans->revision);
        if ($reservedTarget !== null) {
            $reserved = $this->plans->build($reservedTarget->reflection);
            if ($reserved->usages !== []) {
                throw new InvalidConfigurationException(sprintf(
                    'The semantic callback parameter "$%s" must not declare DI attributes.',
                    $reservedTarget->name,
                ));
            }
            $completed->record($reservedTarget->reflection, $reserved);
        }

        $omitted = false;
        foreach ($plan->targets as $target) {
            $result = $this->resolvePreparedParameter(
                $target,
                $state,
                $completed,
                allowOmission: true,
                beforeConsumption: $beforeConsumption,
            );

            // Dependencies and handlers can load source attributes, including on the current parameter.
            $current = $this->refreshPlan($plan);
            if ($current !== $plan) {
                $plan = $current;
                ParameterResolutionBoundary::publicParameters($plan, $providedParameters, $diagnosticOwner);
                $completed->assertUnchanged();
            }

            if ($result === null) {
                $omitted = true;
                continue;
            }

            [$position, $value] = $result;
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
        // Final metadata discovery can reveal a custom source without adding
        // a registered DI attribute to the completed composition.
        $beforeConsumption?->__invoke();
        if ($retainProof) {
            // Only constructor calls retain the proof. Their guard checks
            // provenance without invoking the semantic callback hook.
            $proof = $completed->retainCompletedParameters($beforeConsumption);
        }
        return $arguments;
    }

    /** @internal */
    public function isCurrentPlan(PreparedParameterPlan $plan): bool
    {
        $this->synchronizeAttributeRevision();

        return $this->canCachePlans
            && !$plan->requiresFreshValidation
            && $plan->belongsTo($this->planOwner)
            && $plan->resolverRevision === $this->revision;
    }

    /** @return array{0:int,1:mixed} */
    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): array {
        $completed = new CompletedAttributeGuard($this->plans, $this->plans->revision);
        $this->prepareTarget($target);
        $result = $this->resolvePreparedParameter($target, $context, $completed);
        $completed->assertUnchanged();
        return $result;
    }

    /** @return list<int> */
    public function resolverSlotsFor(ParameterTarget $target): array
    {
        return $this->prepareTarget($target);
    }

    public function target(ReflectionParameter $parameter): ParameterTarget
    {
        return $this->targetFactory->create($parameter);
    }

    /**
     * @param (Closure():void)|null $beforeConsumption
     * @return ($allowOmission is true ? array{0:int,1:mixed}|null : array{0:int,1:mixed})
     */
    private function resolvePreparedParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
        CompletedAttributeGuard $completed,
        bool $allowOmission = false,
        ?Closure $beforeConsumption = null,
    ): ?array {
        $nativeOptional = $allowOmission
            && !$target->variadic
            && $target->reflection->isOptional()
            && $target->reflection->getDeclaringFunction()->isInternal();
        $bootstrap = $this->bootstrap?->isActive === true ? $this->bootstrap : null;
        do {
            $revision = $this->revision;
            $attributePlan = $this->plans->build($target->reflection);
            $pending = $bootstrap === null ? [] : BootstrapResolutionGuard::unavailableDeclarations($target);
            // Discovery can make a source available after the outer plan was
            // accepted. Classify and record one revision before executing work.
            $slots = $this->prepareTarget($target);
        } while ($revision !== $this->revision);
        if ($bootstrap !== null) {
            $pending = [...$pending, ...($this->pendingAttributeQueries[$target] ?? [])];
        }

        try {
            $resolvers = $this->resolverList;
            foreach ($slots as $slot) {
                $beforeConsumption?->__invoke();
                $resolver = $resolvers[$slot];
                if ($resolver instanceof AttributeParameterResolver) {
                    // Record the plan actually consumed by the bridge. Fresh
                    // discovery here could load a handler that never executed.
                    ['result' => $result, 'plan' => $attributePlan] = $resolver->resolveParameterWithPlan($target, $context, $beforeConsumption);
                } else {
                    $result = $resolver->resolveParameter($target, $context);
                }
                if ($result !== null) {
                    $result = \Componenta\DI\Internal\validate_parameter_resolution_result(
                        $result,
                        $resolver,
                        $target,
                        $context,
                    );
                    $completed->record($target->reflection, $attributePlan);
                    if ($bootstrap !== null) {
                        $bootstrap->recordAttributes($target->reflection, plan: $attributePlan);
                        $bootstrap->recordParameter($target, $resolvers, $slots, $slot, $pending);
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
                if ($bootstrap !== null) {
                    $bootstrap->recordAttributes($target->reflection, plan: $attributePlan);
                    $bootstrap->recordParameter($target, $resolvers, $slots, null, $pending);
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
        $this->synchronizeAttributeRevision();

        if (!$plan->requiresFreshValidation && $plan->resolverRevision === $this->revision) {
            return $plan;
        }

        return $this->prepareTargets($plan->targets);
    }

    /**
     * @param-out bool $requiresFreshValidation
     * @return list<int>
     */
    private function prepareTarget(ParameterTarget $target, ?bool &$requiresFreshValidation = null): array
    {
        $requiresFreshValidation = false;
        $this->synchronizeAttributeRevision();

        $cacheable = $this->canCachePlans && $target->canCacheClassification;
        if ($cacheable) {
            $cache = $this->preparedSlots ??= new WeakMap();
            if (isset($cache[$target])) {
                if (!isset($this->pendingAttributeQueries[$target])
                    || !array_any($this->pendingAttributeQueries[$target], ParameterTarget::isAttributeClassLoaded(...))
                ) {
                    return $cache[$target];
                }
                unset($cache[$target]);
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

        do {
            $revision = $this->revision;
            $attributePlan = $this->plans->build($target->reflection);
            if ($target->attributeClasses === []) {
                $queries = [];
                $resolverSlots = $this->classifyResolverSlots($target);
            } else {
                [$resolverSlots, $queries] = $target->observeAttributeQueries($this->classifyResolverSlots(...));
            }
            // Immutable type classification can autoload a class file that
            // also declares attributes. Do not publish slots that skipped a
            // newly available handler, including for single-parameter calls.
            $this->synchronizeAttributeRevision();
        } while ($revision !== $this->revision || ($queries !== [] && array_any($queries, ParameterTarget::isAttributeClassLoaded(...))));

        if ($queries !== []) {
            $pending = $this->pendingAttributeQueries ??= new WeakMap();
            $pending[$target] = $queries;
        } elseif (isset($this->pendingAttributeQueries[$target])) {
            unset($this->pendingAttributeQueries[$target]);
        }

        // Preserve invocation preflight when late or mutable metadata could
        // introduce a user rule, including rules on a later argument.
        $requiresFreshValidation = ($attributePlan->requiresFreshValidation || $target->reflection::class !== ReflectionParameter::class)
            && $this->plans->hasCustomRules;
        if (!$cacheable || $requiresFreshValidation) {
            return $resolverSlots;
        }

        // A cached selection is independent of the lifetime of its target.
        $cache = $this->preparedSlots ??= new WeakMap();
        return $cache[$target] = $resolverSlots;
    }

    private function synchronizeAttributeRevision(): void
    {
        $revision = $this->plans->revision;
        if ($revision === $this->attributeRevision) {
            return;
        }

        $this->attributeRevision = $revision;
        $this->preparedSlots = null;
        $this->pendingAttributeQueries = null;
        ++$this->revision;
    }

    /** @return list<int> */
    private function classifyResolverSlots(ParameterTarget $target): array
    {
        /** @var list<int> $slots */
        $slots = [];
        $registrationCount = count($this->registrations);

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

        // Nested resolution can refresh attribute metadata during autoload.
        // Only actual additions change the resolver chain being classified.
        if ($registrationCount !== count($this->registrations)) {
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

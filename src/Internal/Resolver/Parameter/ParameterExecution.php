<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Parameter;

use Closure;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Exception\ExceptionInterface;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Internal\AttributePlans;
use Componenta\DI\Internal\BootstrapResolutionGuard;
use Componenta\DI\Internal\CompletedAttributeGuard;
use Componenta\DI\Internal\ResolutionMetadata;
use Componenta\DI\Internal\Resolver\Parameter\Request\MappedRequestContext;
use Componenta\DI\Internal\Resolver\Parameter\Request\MappedRequestParameterSourceGuard;
use Componenta\DI\Resolver\Parameter\ArrayResolver;
use Componenta\DI\Resolver\Parameter\ArrayTypedResolver;
use Componenta\DI\Resolver\Parameter\AttributeParameterResolver;
use Componenta\DI\Resolver\Parameter\DefaultValueResolver;
use Componenta\DI\Resolver\Parameter\NullableResolver;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Target\ParameterTarget;
use ReflectionParameter;
use Throwable;

/** Executes the parameter resolver chain and owns prepared plans. @internal */
final class ParameterExecution
{
    public function __construct(
        private readonly AttributePlans $plans,
        private readonly ParameterTargets $targetFactory,
        private readonly ParameterResolverChain $chain,
        private readonly ParameterPlanBuilder $parameterPlans,
        private readonly ?BootstrapResolutionGuard $bootstrap,
    ) {}

    /** @internal */
    public function usesAttributePlans(AttributePlans $plans): bool
    {
        return $this->plans === $plans;
    }

    public bool $isSealed {
        get => $this->chain->isSealed;
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
        return $this->parameterPlans->prepare($targets);
    }

    /**
     * @internal
     * @param list<ParameterTarget> $targets
     */
    public function prepareTargets(array $targets): PreparedParameterPlan
    {
        return $this->parameterPlans->prepare($targets);
    }

    /**
     * @param list<ParameterTarget> $targets
     * @param array<string|int,mixed> $providedParameters
     * @return array<array-key,mixed>
     */
    public function resolveTargets(array $targets, array $providedParameters = []): array
    {
        return $this->resolvePrepared(
            $this->parameterPlans->prepare($targets),
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
        ?MappedRequestContext $provenance = null,
    ): array {
        return $this->resolvePreparedInvocation($plan, $providedParameters, provenance: $provenance);
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
        ?MappedRequestContext $provenance = null,
    ): array {
        return $this->resolvePreparedInvocation($plan, $providedParameters, provenance: $provenance, proof: $proof, retainProof: true);
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
        ?MappedRequestContext $provenance = null,
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
            $provenance,
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
        ?MappedRequestContext $provenance = null,
        ?CompletedAttributeGuard &$proof = null,
        bool $retainProof = false,
    ): array {
        $this->parameterPlans->assertOwned($plan);
        $plan = $this->parameterPlans->refresh($plan);
        if ($provenance !== null) {
            MappedRequestParameterSourceGuard::assertTargetsProvenanceNoConflicts($plan->targets, $provenance, $diagnosticOwner);
        }
        [$context, $accept, $literalContext] = ParameterContextAccess::begin(
            ResolutionMetadata::publicParameters($providedParameters),
            $arguments,
        );
        $beforeConsumption = $provenance === null
            ? $beforeInvocation
            : ParameterResolutionBoundary::beforeConsumption($plan->targets, $provenance, $diagnosticOwner, $beforeInvocation);

        $completed = new CompletedAttributeGuard($this->plans, $this->plans->refreshAvailability());
        if ($reservedTarget !== null) {
            $declaration = ParameterTargetAccess::declaration($reservedTarget);
            $reserved = $declaration->attributePlan($this->plans);
            if ($reserved->usages !== []) {
                throw new InvalidConfigurationException(sprintf(
                    'The semantic callback parameter "$%s" must not declare DI attributes.',
                    $reservedTarget->name,
                ));
            }
            if ($declaration->mayHaveAttributes) {
                $completed->record($declaration->metadataTarget(), $reserved, captured: $declaration->capturedAttributes);
            }
        }

        $omitted = false;
        foreach ($plan->targets as $index => $original) {
            $target = ParameterTargetAccess::forResolution($original);
            $check = $beforeConsumption;
            if ($provenance !== null && $target !== $original) {
                // Validate the chosen declarations for the active parameter and
                // keep watching the other candidates as their metadata changes.
                $targets = $plan->targets;
                $targets[$index] = $target;
                $check = ParameterResolutionBoundary::beforeConsumption($targets, $provenance, $diagnosticOwner, $beforeInvocation);
            }
            $result = $this->resolvePreparedParameter(
                $target,
                $context,
                $completed,
                allowOmission: true,
                beforeConsumption: $check,
                literalContext: $literalContext,
            );

            // Dependencies and handlers can load source attributes, including on the current parameter.
            $current = $this->parameterPlans->refresh($plan);
            if ($current !== $plan) {
                $plan = $current;
                if ($provenance !== null) {
                    MappedRequestParameterSourceGuard::assertTargetsProvenanceNoConflicts($plan->targets, $provenance, $diagnosticOwner);
                }
                $completed->assertUnchanged();
            }

            if ($result === null) {
                $omitted = true;
                continue;
            }

            [$position, $value] = $result;
            $accept($position, $value);
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
        return $this->parameterPlans->isCurrent($plan);
    }

    /** @return array{0:int,1:mixed} */
    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): array {
        $completed = new CompletedAttributeGuard($this->plans, $this->plans->refreshAvailability());
        $target = ParameterTargetAccess::forResolution($target);
        $this->parameterPlans->slotsFor($target);
        $result = $this->resolvePreparedParameter($target, $context, $completed);
        $completed->assertUnchanged();
        return $result;
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
        ?ParameterResolutionContext $literalContext = null,
    ): ?array {
        $declaration = ParameterTargetAccess::declaration($target);
        $nativeOptional = $allowOmission && $declaration->isOmittable($target->variadic);
        $bootstrap = $this->bootstrap?->isActive === true ? $this->bootstrap : null;
        do {
            $revision = $this->parameterPlans->revision;
            $attributePlan = $declaration->attributePlan($this->plans);
            // Discovery can make a source available after the outer plan was
            // accepted. Classify and record one revision before executing work.
            $slots = $this->parameterPlans->slotsFor($target);
        } while ($revision !== $this->parameterPlans->revision);
        $pending = $declaration->mayHaveAttributes ? $this->parameterPlans->pendingAttributes($target) : [];

        try {
            $resolvers = $this->chain->resolvers;
            $winner = null;
            $result = null;
            foreach ($slots as $slot) {
                $beforeConsumption?->__invoke();
                $resolver = $resolvers[$slot];
                if ($resolver instanceof AttributeParameterResolver) {
                    // Record the plan actually consumed by the bridge. Fresh
                    // discovery here could load a handler that never executed.
                    ['result' => $result, 'plan' => $attributePlan] = ParameterAttributeAccess::resolveWithPlan(
                        $resolver,
                        $target,
                        $context,
                        $beforeConsumption,
                        $literalContext,
                    );
                } else {
                    $input = $resolver instanceof ArrayResolver || $resolver instanceof ArrayTypedResolver
                        ? $literalContext ?? $context
                        : $context;
                    $result = $resolver->resolveParameter($target, $input);
                }
                if ($result === null) {
                    continue;
                }
                $result = \Componenta\DI\Internal\validate_parameter_resolution_result(
                    $result,
                    $resolver,
                    $target,
                    $context,
                );
                $winner = $slot;
                break;
            }
            if ($winner !== null || $nativeOptional) {
                self::recordParameterAttributes($declaration, $attributePlan, $completed, $bootstrap);
                if ($bootstrap !== null) {
                    $bootstrap->recordParameter($target, $resolvers, $slots, $winner, $pending);
                } elseif ($pending !== []) {
                    $completed->recordParameterClassification(
                        $this->parameterPlans->watchCompletedSelection($target, $resolvers, $slots, $winner, $pending, $beforeConsumption),
                    );
                }
                // Native defaults may depend on omission (e.g. array_keys()).
                // Keep explicit and extension-provided values, but let PHP
                // supply defaults instead of synthesizing argument values.
                if ($nativeOptional
                    && ($winner === null
                        || $resolvers[$winner] instanceof DefaultValueResolver
                        || $resolvers[$winner] instanceof NullableResolver)
                ) {
                    return null;
                }
                return $result;
            }
        } catch (ExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ParameterContextAccess::failure(
                $context,
                $target->reflection,
                previous: $e,
            );
        }

        throw ParameterContextAccess::failure(
            $context,
            $target->reflection,
        );
    }

    private static function recordParameterAttributes(
        ParameterDeclaration $declaration,
        AttributePlan $plan,
        CompletedAttributeGuard $completed,
        ?BootstrapResolutionGuard $bootstrap,
    ): void {
        if (!$declaration->mayHaveAttributes) {
            return;
        }

        $reflection = $declaration->borrowedMetadata ?? $declaration->metadataTarget();
        $consumed = $declaration->resolutionAttributes;
        $completed->record($reflection, $plan, captured: $declaration->capturedAttributes, consumed: $consumed);
        $bootstrap?->recordAttributes($reflection, plan: $plan, captured: $declaration->capturedAttributes, consumed: $consumed);
    }

    private function __clone() {}
}

<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Exception\ExceptionInterface;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Internal\Resolver\Parameter\ParameterResolutionBoundary;
use Componenta\DI\Internal\Resolver\Parameter\PreparedParameter;
use Componenta\DI\Internal\Resolver\Parameter\PreparedParameterPlan;
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
    /** @var WeakMap<ParameterTarget,PreparedParameter>|null */
    private ?WeakMap $preparedParameters = null;

    private int $revision = 0;
    private int $order = 0;
    private bool $sealed = false;
    private ParameterTargetFactory $targetFactory;
    private readonly object $planOwner;

    public function __construct(
        private readonly AttributePlanBuilder $plans,
        ?ParameterTargetFactory $targetFactory = null,
    ) {
        $this->targetFactory = $targetFactory ?? new ParameterTargetFactory();
        $this->planOwner = new \stdClass();
    }

    public bool $isSealed {
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
     * @return array<int,mixed>
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
        $prepared = [];
        foreach ($targets as $target) {
            $prepared[] = $this->prepareTarget($target);
        }

        return new PreparedParameterPlan(
            $prepared,
            $targets,
            $this->revision,
            $this->planOwner,
        );
    }

    /**
     * @param list<ParameterTarget> $targets
     * @param array<string|int,mixed> $providedParameters
     * @return array<int,mixed>
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
     * @return array<int,mixed>
     */
    public function resolvePrepared(
        PreparedParameterPlan $plan,
        array $providedParameters = [],
    ): array {
        $plan = $this->refreshPlan($plan);
        $state = new ParameterResolutionContext(
            ParameterResolutionBoundary::publicParameters($plan, $providedParameters),
        );

        foreach ($plan->parameters as $prepared) {
            [$position, $value] = $this->resolvePreparedParameter($prepared, $state);
            $state->resolve($position, $value);
        }

        return $state->resolved;
    }

    /** @internal */
    public function isCurrentPlan(PreparedParameterPlan $plan): bool
    {
        return $this->sealed
            && $plan->owner === $this->planOwner
            && $plan->resolverRevision === $this->revision;
    }

    /** @return array{0:int,1:mixed} */
    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): array {
        return $this->resolvePreparedParameter($this->prepareTarget($target), $context);
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

    /** @return array{0:int,1:mixed} */
    private function resolvePreparedParameter(
        PreparedParameter $prepared,
        ParameterResolutionContext $context,
    ): array {
        $target = $prepared->target;

        try {
            $resolvers = $this->resolverList;
            foreach ($prepared->resolverSlots as $slot) {
                $resolver = $resolvers[$slot];
                $result = $resolver->resolveParameter($target, $context);
                if ($result !== null) {
                    return \Componenta\DI\Internal\validate_parameter_resolution_result(
                        $result,
                        $resolver,
                        $target,
                        $context,
                    );
                }
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

        if ($plan->resolverRevision === $this->revision) {
            return $plan;
        }

        return $this->prepareTargets($plan->targets);
    }

    private function prepareTarget(ParameterTarget $target): PreparedParameter
    {
        $cacheable = $this->sealed && self::isStableTarget($target);
        if ($cacheable) {
            $cache = $this->preparedParameters ??= new WeakMap();
            if (isset($cache[$target])) {
                return $cache[$target];
            }
        }

        $unsupportedReason = match (true) {
            $target->variadic => 'Variadic parameters are not supported by the DI resolver contract.',
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

    private static function isStableTarget(ParameterTarget $target): bool
    {
        $function = $target->reflection->getDeclaringFunction();

        // ReflectionParameter may expose a closure scoped to a method through
        // ReflectionMethod. isClosure() is therefore the semantic distinction:
        // PreparedParameter retains its target, and caching any closure-owned
        // target would retain the declaring Closure and its captures.
        return !$function->isClosure();
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

<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Parameter\Request\MappedRequestParameterSourceGuard;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Resolver\Target\ParameterTargetFactory;
use ReflectionParameter;
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
    private ?WeakMap $supportedSlots = null;

    private int $revision = 0;
    private int $order = 0;
    private bool $sealed = false;
    private ParameterTargetFactory $targetFactory;

    public function __construct(
        private readonly AttributePlanBuilder $plans,
        ?ParameterTargetFactory $targetFactory = null,
    ) {
        $this->targetFactory = $targetFactory ?? new ParameterTargetFactory();
    }

    /** Higher priorities run first; equal priorities preserve insertion order. */
    public function add(ParameterResolverInterface $resolver, int $priority = 0): void
    {
        if ($this->sealed) {
            throw new \LogicException('Parameter resolver pipeline is sealed and cannot be changed.');
        }

        $objectId = spl_object_id($resolver);
        if (isset($this->registered[$objectId])) {
            throw new \InvalidArgumentException(sprintf(
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
        $this->supportedSlots = null;
        ++$this->revision;
    }

    public function seal(): void
    {
        $this->registered = [];
        $this->sealed = true;
    }

    /** @return list<ParameterResolverInterface> */
    public array $resolverList {
        get => $this->ordered ??= array_map(
            static fn(array $registration): ParameterResolverInterface => $registration['resolver'],
            $this->registrationsInOrder(),
        );
    }

    /**
     * Stable semantic registration view used by AOT/cache fingerprinting.
     *
     * @return list<array{resolver:ParameterResolverInterface,priority:int}>
     */
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

    /** @param list<ReflectionParameter> $parameters @return list<ParameterTarget> */
    public function targets(array $parameters): array
    {
        $targets = [];
        foreach ($parameters as $parameter) {
            $targets[] = $this->target($parameter);
        }
        return $targets;
    }

    /**
     * @param list<ParameterTarget> $targets
     * @param array<string|int,mixed> $providedParameters
     * @return array<int,mixed>
     */
    public function resolveTargets(array $targets, array $providedParameters = []): array
    {
        $state = new ParameterResolutionContext($providedParameters);

        foreach ($targets as $target) {
            [$position, $value] = $this->resolveParameter($target, $state);
            $state->resolve($position, $value);
        }

        return $state->resolved;
    }

    /** @return array{0:int,1:mixed} */
    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): array {
        $unsupportedReason = match (true) {
            $target->variadic => 'Variadic parameters are not supported by the DI resolver contract.',
            $target->byReference => 'By-reference parameters are not supported by the DI resolver contract.',
            default => null,
        };

        if ($unsupportedReason !== null) {
            throw ResolutionException::forParameter(
                $target->reflection,
                reason: $unsupportedReason,
                providedParameters: $context->provided,
                resolvedParameters: $context->resolved,
            );
        }

        // Composition validates parameter attributes, but never produces a
        // parameter value. Execution continues exclusively through resolvers.
        $this->plans->build($target->reflection);
        MappedRequestParameterSourceGuard::assertTargetContextNoConflicts($target, $context);

        foreach ($this->resolverSlotsFor($target) as $slot) {
            $resolver = $this->resolverList[$slot];
            $result = $resolver->resolveParameter($target, $context);
            if ($result !== null) {
                return ParameterResolutionResult::validate($result, $resolver, $target, $context);
            }
        }

        throw ResolutionException::forParameter(
            $target->reflection,
            providedParameters: $context->provided,
            resolvedParameters: $context->resolved,
        );
    }

    /** @return list<int> */
    public function resolverSlotsFor(ParameterTarget $target): array
    {
        $cache = $this->supportedSlots ??= new WeakMap();
        if (isset($cache[$target])) {
            return $cache[$target];
        }

        $slots = [];
        $revision = $this->revision;
        foreach ($this->resolverList as $slot => $resolver) {
            if ($resolver->supports($target)) {
                $slots[] = $slot;
            }
        }

        if ($revision !== $this->revision) {
            throw new \LogicException('Parameter resolver supports() must not mutate the resolver chain.');
        }

        return $cache[$target] = $slots;
    }

    public function target(ReflectionParameter $parameter): ParameterTarget
    {
        return $this->targetFactory->create($parameter);
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

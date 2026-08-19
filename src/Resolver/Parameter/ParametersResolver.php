<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Exception\ExceptionInterface;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\ResolutionException;
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
    private ?WeakMap $supportedSlots = null;
    /** @var WeakMap<ParameterTarget,list<ParameterResolverInterface>>|null */
    private ?WeakMap $supportedResolvers = null;
    /** @var WeakMap<ParameterTarget,true>|null */
    private ?WeakMap $preparedTargets = null;

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
        $this->supportedSlots = null;
        $this->supportedResolvers = null;
        $this->preparedTargets = null;
        ++$this->revision;
    }

    public function seal(): void
    {
        $this->registered = [];
        $this->supportedSlots = null;
        $this->supportedResolvers = null;
        $this->preparedTargets = null;
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
     * @param list<ParameterTarget> $targets
     * @param array<string|int,mixed> $providedParameters
     * @return array<int,mixed>
     */
    public function resolveTargets(array $targets, array $providedParameters = []): array
    {
        $state = new ParameterResolutionContext($providedParameters);
        $guardMappedSources = $state->mappedRequest !== null;

        foreach ($targets as $target) {
            [$position, $value] = $this->resolveTarget($target, $state, $guardMappedSources);
            $state->resolve($position, $value);
        }

        return $state->resolved;
    }

    /** @return array{0:int,1:mixed} */
    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): array {
        return $this->resolveTarget(
            $target,
            $context,
            $context->mappedRequest !== null,
        );
    }

    /** @return list<int> */
    public function resolverSlotsFor(ParameterTarget $target): array
    {
        if ($this->sealed) {
            $cache = $this->supportedSlots ??= new WeakMap();
            if (isset($cache[$target])) {
                return $cache[$target];
            }
        }

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

        if (!$this->sealed) {
            return $slots;
        }

        $cache = $this->supportedSlots ??= new WeakMap();
        return $cache[$target] = $slots;
    }

    public function target(ReflectionParameter $parameter): ParameterTarget
    {
        return $this->targetFactory->create($parameter);
    }

    /** @return array{0:int,1:mixed} */
    private function resolveTarget(
        ParameterTarget $target,
        ParameterResolutionContext $context,
        bool $guardMappedSources,
    ): array {
        try {
            $this->prepareTarget($target);
            if ($guardMappedSources) {
                MappedRequestParameterSourceGuard::assertTargetContextNoConflicts($target, $context);
            }

            foreach ($this->resolversFor($target) as $resolver) {
                $result = $resolver->resolveParameter($target, $context);
                if ($result !== null) {
                    return \Componenta\DI\validate_parameter_resolution_result(
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

    private function prepareTarget(ParameterTarget $target): void
    {
        if ($this->sealed) {
            $cache = $this->preparedTargets ??= new WeakMap();
            if (isset($cache[$target])) {
                return;
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
        $this->resolversFor($target);

        if ($this->sealed) {
            $cache = $this->preparedTargets ??= new WeakMap();
            $cache[$target] = true;
        }
    }

    /** @return list<ParameterResolverInterface> */
    private function resolversFor(ParameterTarget $target): array
    {
        if ($this->sealed) {
            $cache = $this->supportedResolvers ??= new WeakMap();
            if (isset($cache[$target])) {
                return $cache[$target];
            }
        }

        $resolvers = [];
        foreach ($this->resolverSlotsFor($target) as $slot) {
            $resolvers[] = $this->resolverList[$slot];
        }

        if (!$this->sealed) {
            return $resolvers;
        }

        $cache = $this->supportedResolvers ??= new WeakMap();
        return $cache[$target] = $resolvers;
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

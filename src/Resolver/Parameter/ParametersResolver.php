<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Resolver\Target\ParameterTargetFactory;
use Componenta\Stdlib\PriorityList;
use ReflectionParameter;
use WeakMap;

/** Aggregates parameter resolvers in their exact runtime order. */
class ParametersResolver
{
    /** @var PriorityList<ParameterResolverInterface> */
    private PriorityList $items;

    /** @var array<int, true> */
    private array $registered = [];

    /** @var list<ParameterResolverInterface>|null */
    private ?array $ordered = null;

    private int $revision = 0;

    private bool $sealed = false;

    private ?ParameterTargetFactory $targetFactory = null;

    /** @var WeakMap<ParameterTarget, list<int>>|null */
    private ?WeakMap $supportedSlots = null;

    /** @var list<ParameterResolverInterface> */
    public array $resolverList {
        get => $this->ordered ??= iterator_to_array($this->items, false);
    }

    public function __construct(ParameterResolverInterface ...$resolvers)
    {
        $this->items = new PriorityList();

        foreach ($resolvers as $resolver) {
            $this->add($resolver);
        }
    }

    /** Higher priorities run first; equal priorities preserve insertion order. */
    public function add(ParameterResolverInterface $resolver, int $priority = 0): void
    {
        if ($this->sealed) {
            throw new \LogicException(
                'Parameter resolver pipeline is sealed and cannot be changed.',
            );
        }

        $objectId = spl_object_id($resolver);
        if (isset($this->registered[$objectId])) {
            throw new \InvalidArgumentException(sprintf(
                'Parameter resolver %s is already registered.',
                $resolver::class,
            ));
        }

        $this->items->insert($resolver, $priority);
        $this->registered[$objectId] = true;
        $this->ordered = null;
        $this->supportedSlots = null;
        ++$this->revision;
    }

    /** Prevents runtime drift after the container composition is complete. */
    public function seal(): void
    {
        $this->registered = [];
        $this->sealed = true;
    }

    /**
     * @param list<ReflectionParameter> $parameters
     * @param array<string|int, mixed> $providedParameters
     * @return array<int, mixed>
     */
    public function resolve(
        array $parameters,
        array $providedParameters = [],
    ): array {
        return $this->resolveTargets(
            $this->targets($parameters),
            $providedParameters,
        );
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
     * @param array<string|int, mixed> $providedParameters
     * @return array<int, mixed>
     */
    public function resolveTargets(
        array $targets,
        array $providedParameters = [],
    ): array {
        $context = new ParameterResolutionContext($providedParameters);

        foreach ($targets as $target) {
            [$position, $value] = $this->resolveParameter($target, $context);
            $context->resolve($position, $value);
        }

        return $context->resolved;
    }

    /** @return array{0: int, 1: mixed} */
    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): array {
        $unsupportedReason = match (true) {
            $target->variadic
                => 'Variadic parameters are not supported by the DI resolver contract.',
            $target->byReference
                => 'By-reference parameters are not supported by the DI resolver contract.',
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

        foreach ($this->resolverSlotsFor($target) as $slot) {
            $resolver = $this->resolverList[$slot];
            $result = $resolver->resolveParameter($target, $context);

            if ($result !== null) {
                return ParameterResolutionResult::validate(
                    $result,
                    $resolver,
                    $target,
                    $context,
                );
            }
        }

        throw ResolutionException::forParameter(
            $target->reflection,
            providedParameters: $context->provided,
            resolvedParameters: $context->resolved,
        );
    }

    public function target(ReflectionParameter $parameter): ParameterTarget
    {
        return ($this->targetFactory ??= new ParameterTargetFactory())
            ->create($parameter);
    }

    /**
     * Returns the exact runtime resolver slots applicable to this target.
     * Generated factories consume this method rather than duplicating
     * supports() checks or reconstructing resolver order.
     *
     * @return list<int>
     */
    public function resolverSlotsFor(ParameterTarget $target): array
    {
        $cache = $this->supportedSlots ??= new WeakMap();

        if (isset($cache[$target])) {
            return $cache[$target];
        }

        $slots = [];
        $revision = $this->revision;
        $resolvers = $this->resolverList;

        foreach ($resolvers as $slot => $resolver) {
            if ($resolver->supports($target)) {
                $slots[] = $slot;
            }
        }

        if ($revision !== $this->revision) {
            throw new \LogicException(
                'Parameter resolver supports() must not mutate the resolver chain.',
            );
        }

        return $cache[$target] = $slots;
    }
}

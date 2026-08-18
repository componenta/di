<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\ResolutionContext;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Resolver\Target\ParameterTargetFactory;
use Componenta\DI\Value\ValuePipeline;
use ReflectionParameter;
use WeakMap;

/**
 * Orchestrates the ordered ParameterResolverInterface chain.
 *
 * Parameter values are never produced directly by this class. The current v5
 * value pipeline is temporarily isolated behind AttributeParameterResolver
 * instances until every built-in attribute has its dedicated v5 resolver.
 */
final class ParametersResolver
{
    /** @var list<array{resolver: ParameterResolverInterface, priority: int, order: int}> */
    private array $registrations = [];

    /** @var list<ParameterResolverInterface>|null */
    private ?array $ordered = null;

    /** @var array<int, true> */
    private array $registered = [];

    /** @var WeakMap<ParameterTarget, list<int>>|null */
    private ?WeakMap $supportedSlots = null;

    private int $revision = 0;
    private int $order = 0;
    private bool $sealed = false;
    private ParameterTargetFactory $targetFactory;

    public function __construct(
        AttributePlanBuilder $plans,
        ValuePipeline $values,
        ?ParameterTargetFactory $targetFactory = null,
    ) {
        $this->targetFactory = $targetFactory ?? new ParameterTargetFactory();

        // Preserve v4 ordering while attribute-aware resolvers are migrated.
        $this->add(new AttributeParameterResolver(
            $plans,
            $values,
            AttributeParameterResolver::TRANSFORMER,
        ), 1200);
        $this->add(new ArrayResolver(), 1100);
        $this->add(new ArrayTypedResolver(), 1000);
        $this->add(new AttributeParameterResolver(
            $plans,
            $values,
            AttributeParameterResolver::PROVIDER,
        ), 900);
        $this->add(new AttributeParameterResolver(
            $plans,
            $values,
            AttributeParameterResolver::LEGACY_FALLBACK,
        ), -1000);
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
        get {
            if ($this->ordered !== null) {
                return $this->ordered;
            }

            $registrations = $this->registrations;
            usort(
                $registrations,
                static fn(array $left, array $right): int =>
                    $right['priority'] <=> $left['priority']
                    ?: $left['order'] <=> $right['order'],
            );

            return $this->ordered = array_map(
                static fn(array $registration): ParameterResolverInterface => $registration['resolver'],
                $registrations,
            );
        }
    }

    /**
     * @param list<ReflectionParameter> $parameters
     * @return array<int, mixed>
     */
    public function resolve(
        array $parameters,
        ResolutionContext $context = new ResolutionContext(),
    ): array {
        return $this->resolveTargets($this->targets($parameters), $context);
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
     * @return array<int, mixed>
     */
    public function resolveTargets(
        array $targets,
        ResolutionContext $context = new ResolutionContext(),
    ): array {
        $state = new ParameterResolutionContext($context->visible());

        foreach ($targets as $target) {
            [$position, $value] = $this->resolveParameter($target, $state);
            $state->resolve($position, $value);
        }

        return $state->resolved;
    }

    /** @return array{0: int, 1: mixed} */
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
}

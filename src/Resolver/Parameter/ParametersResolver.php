<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Internal\AttributePlanAccess;
use Componenta\DI\Internal\BootstrapResolutionGuard;
use Componenta\DI\Internal\Resolver\Parameter\ParameterExecution;
use Componenta\DI\Internal\Resolver\Parameter\ParameterExecutionScope;
use Componenta\DI\Internal\Resolver\Parameter\ParameterPlanBuilder;
use Componenta\DI\Internal\Resolver\Parameter\ParameterResolverChain;
use Componenta\DI\Internal\Resolver\Parameter\ParameterTargetFactoryAccess;
use Componenta\DI\Internal\Resolver\Parameter\ParameterTargets;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Resolver\Target\ParameterTargetFactory;
use ReflectionParameter;

/** Resolves arguments through an ordered chain of parameter resolvers. */
final class ParametersResolver extends ParameterExecutionScope
{
    private readonly ParameterResolverChain $chain;
    private readonly ParameterPlanBuilder $plans;
    private readonly ?BootstrapResolutionGuard $bootstrap;

    public function __construct(AttributePlanBuilder $plans, ?ParameterTargetFactory $targets = null)
    {
        $targetCache = $targets === null ? new ParameterTargets() : ParameterTargetFactoryAccess::targets($targets);
        $attributes = AttributePlanAccess::plans($plans);
        $this->bootstrap = AttributePlanAccess::bootstrap($plans);
        $this->chain = new ParameterResolverChain();
        $this->plans = new ParameterPlanBuilder($attributes, $targetCache, $this->chain);
        parent::__construct(new ParameterExecution($attributes, $targetCache, $this->chain, $this->plans, $this->bootstrap));
    }

    public bool $isSealed {
        get => $this->chain->isSealed;
    }

    /** Higher priorities run first; equal priorities preserve insertion order. */
    public function add(ParameterResolverInterface $resolver, int $priority = 0): void
    {
        $this->chain->add($resolver, $priority);
        $this->plans->chainChanged();
    }

    public function seal(): void
    {
        $this->chain->seal();
        $this->plans->clearClassifications();
        if ($this->bootstrap?->isActive === true) {
            $this->bootstrap->recordSealedParameterChain($this->chain->resolvers);
        }
    }

    /**
     * @param list<ReflectionParameter> $parameters
     * @param array<string|int,mixed> $providedParameters
     * @return array<array-key,mixed>
     */
    public function resolve(array $parameters, array $providedParameters = []): array
    {
        return $this->execution->resolve($parameters, $providedParameters);
    }

    /**
     * @param list<ReflectionParameter> $parameters
     * @return list<ParameterTarget>
     */
    public function targets(array $parameters): array
    {
        return $this->execution->targets($parameters);
    }

    /**
     * @param list<ParameterTarget> $targets
     * @param array<string|int,mixed> $providedParameters
     * @return array<array-key,mixed>
     */
    public function resolveTargets(array $targets, array $providedParameters = []): array
    {
        return $this->execution->resolveTargets($targets, $providedParameters);
    }

    /** @return array{0:int,1:mixed} */
    public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): array
    {
        return $this->execution->resolveParameter($target, $context);
    }

    public function target(ReflectionParameter $parameter): ParameterTarget
    {
        return $this->execution->target($parameter);
    }
}

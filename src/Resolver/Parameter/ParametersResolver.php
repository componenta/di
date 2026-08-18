<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\ResolutionContext;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Resolver\Target\ParameterTargetFactory;
use Componenta\DI\Value\ValueContext;
use Componenta\DI\Value\ValuePipeline;
use ReflectionParameter;

/** Resolves constructor/callable parameters through the shared semantic value pipeline. */
final class ParametersResolver
{
    private ParameterTargetFactory $targetFactory;

    public function __construct(
        private readonly AttributePlanBuilder $plans,
        private readonly ValuePipeline $values,
        ?ParameterTargetFactory $targetFactory = null,
    ) {
        $this->targetFactory = $targetFactory ?? new ParameterTargetFactory();
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
        $resolved = [];

        foreach ($targets as $target) {
            if ($target->variadic) {
                throw ResolutionException::forParameter(
                    $target->reflection,
                    reason: 'Variadic parameters are not supported by DI value resolution.',
                    providedParameters: $context->visible(),
                    resolvedParameters: $resolved,
                );
            }

            if ($target->byReference) {
                throw ResolutionException::forParameter(
                    $target->reflection,
                    reason: 'By-reference parameters are not supported by DI value resolution.',
                    providedParameters: $context->visible(),
                    resolvedParameters: $resolved,
                );
            }

            $plan = $this->plans->build($target->reflection);
            $resolved[$target->position] = $this->values->resolve(
                $target,
                $plan,
                new ValueContext($context, $resolved),
            );
        }

        return $resolved;
    }

    public function target(ReflectionParameter $parameter): ParameterTarget
    {
        return $this->targetFactory->create($parameter);
    }
}

<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Parameter;

use Closure;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Resolver\Parameter\AttributeParameterResolver;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Target\ParameterTarget;

/** Executes parameter handlers and reports the plan consumed by their resolver. @internal */
abstract class ParameterAttributeAccess extends ParameterAttributeScope
{
    /**
     * @param (Closure():void)|null $beforeConsumption
     * @return array{result:array{0:int,1:mixed}|null,plan:AttributePlan}
     */
    public static function resolveWithPlan(
        AttributeParameterResolver $resolver,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        ?Closure $beforeConsumption,
        ?ParameterResolutionContext $literalContext = null,
    ): array {
        return $resolver->execution->resolveParameterWithPlan($target, $context, $beforeConsumption, $literalContext);
    }
}

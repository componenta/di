<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Attribute;

use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Target\ParameterTarget;

/**
 * Attribute handler capable of producing the value of a reflected parameter.
 *
 * Parameter execution is still owned exclusively by ParameterResolverInterface:
 * AttributeParameterResolver is the single adapter that invokes this contract.
 */
interface ParameterAttributeHandlerInterface extends AttributeHandlerInterface
{
    public function resolveParameter(
        object $attribute,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        AttributePlan $plan,
    ): mixed;
}

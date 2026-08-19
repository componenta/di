<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Attribute;

use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Resolver\Parameter\ParameterAttributeValue;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Target\ParameterTarget;

/**
 * Executes one composed parameter attribute usage.
 *
 * This contract is deliberately independent from AttributeHandlerInterface:
 * parameter-only handlers do not need a meaningless class/property/method
 * handle() implementation. A handler that supports both surfaces implements
 * both interfaces explicitly.
 */
interface ParameterAttributeHandlerInterface
{
    public function resolveParameter(
        object $attribute,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        AttributePlan $plan,
        ParameterAttributeValue $value,
    ): ParameterAttributeValue;
}

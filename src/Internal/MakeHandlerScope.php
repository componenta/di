<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Closure;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Parameter\ParameterAttributeValue;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Reflector;

/** Composes Make and Proxy with the active attempt's attribute instances. @internal */
abstract class MakeHandlerScope
{
    /** @param (Closure(class-string):(?object))|null $findCompanion */
    abstract protected function handleComposed(
        object $attribute,
        Reflector $target,
        ObjectCreationContext $context,
        ?Closure $findCompanion,
    ): void;

    /** @param (Closure(class-string):(?object))|null $findCompanion */
    abstract protected function resolveComposed(
        object $attribute,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        AttributePlan $plan,
        ParameterAttributeValue $value,
        ?Closure $findCompanion,
    ): ParameterAttributeValue;
}

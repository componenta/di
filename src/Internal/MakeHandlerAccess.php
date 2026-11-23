<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Closure;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Parameter\ParameterAttributeValue;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Reflector;

/** Connects an execution's materializer to the built-in Make handler. @internal */
abstract class MakeHandlerAccess extends MakeHandlerScope
{
    /** @param Closure(AttributeUsage):object $materialize */
    public static function handle(
        MakeHandlerScope $handler,
        object $attribute,
        Reflector $target,
        ObjectCreationContext $context,
        Closure $materialize,
    ): void {
        $handler->handleComposed($attribute, $target, $context, $materialize);
    }

    /** @param Closure(AttributeUsage):object $materialize */
    public static function resolve(
        MakeHandlerScope $handler,
        object $attribute,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        AttributePlan $plan,
        ParameterAttributeValue $value,
        Closure $materialize,
    ): ParameterAttributeValue {
        return $handler->resolveComposed($attribute, $target, $context, $plan, $value, $materialize);
    }
}

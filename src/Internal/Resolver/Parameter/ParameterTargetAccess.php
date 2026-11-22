<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Parameter;

use Componenta\DI\Resolver\Target\ParameterTarget;
use ReflectionParameter;

/** Reads declaration metadata and scopes attribute queries to an observer. @internal */
abstract class ParameterTargetAccess extends ParameterTargetScope
{
    public static function declaration(ParameterTarget $target): ParameterDeclaration
    {
        return $target->declaration;
    }

    public static function create(ReflectionParameter $parameter, ParameterAttributeObservation $observer): ParameterTarget
    {
        $target = new ParameterTarget($parameter);
        $target->attributeObserver = $observer;
        return $target;
    }

    public static function forObservation(ParameterTarget $target, ParameterAttributeObservation $observer): ParameterTarget
    {
        if ($target->attributeObserver === $observer) {
            return $target;
        }
        $target = clone $target;
        $target->attributeObserver = $observer;
        return $target;
    }
}

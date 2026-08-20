<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Target;

use ReflectionFunction;
use ReflectionParameter;

/** Creates and reuses immutable parameter targets for stable named reflectors. */
final class ParameterTargetFactory
{
    /** @var array<string, ParameterTarget> */
    private array $namedTargets = [];

    public function create(ReflectionParameter $parameter): ParameterTarget
    {
        $function = $parameter->getDeclaringFunction();

        if ($function instanceof ReflectionFunction && $function->isClosure()) {
            // ParameterTarget keeps its ReflectionParameter, and the reflector
            // keeps its declaring Closure. Caching that target under the same
            // closure would create a value -> key strong reference even in a
            // WeakMap and retain the closure plus captured request state.
            return new ParameterTarget($parameter);
        }

        $class = $parameter->getDeclaringClass()?->getName();
        $key = $class === null
            ? sprintf('function:%s:%d', $function->getName(), $parameter->getPosition())
            : sprintf('method:%s::%s:%d', $class, $function->getName(), $parameter->getPosition());

        return $this->namedTargets[$key] ??= new ParameterTarget($parameter);
    }
}

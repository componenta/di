<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Target;

use ReflectionParameter;

/** Creates and reuses immutable parameter targets for stable named reflectors. */
final class ParameterTargetFactory
{
    /** @var array<string, ParameterTarget> */
    private array $namedTargets = [];

    public function create(ReflectionParameter $parameter): ParameterTarget
    {
        $function = $parameter->getDeclaringFunction();

        if ($function->isClosure()) {
            // ReflectionParameter may expose a method-scoped closure through
            // ReflectionMethod rather than ReflectionFunction. isClosure() is
            // the semantic check that works for both representations.
            // ParameterTarget keeps its ReflectionParameter, and the reflector
            // keeps its declaring Closure, so closure targets are never cached.
            return new ParameterTarget($parameter);
        }

        $class = $parameter->getDeclaringClass()?->getName();
        $key = $class === null
            ? sprintf('function:%s:%d', $function->getName(), $parameter->getPosition())
            : sprintf('method:%s::%s:%d', $class, $function->getName(), $parameter->getPosition());

        return $this->namedTargets[$key] ??= new ParameterTarget($parameter);
    }
}

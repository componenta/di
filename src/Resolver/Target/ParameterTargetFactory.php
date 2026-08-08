<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Target;

use Closure;
use ReflectionFunction;
use ReflectionParameter;
use WeakMap;

/** Creates and reuses immutable parameter targets for native reflectors. */
final class ParameterTargetFactory
{
    /** @var array<string, ParameterTarget> */
    private array $namedTargets = [];

    /** @var WeakMap<Closure, array<int, ParameterTarget>> */
    private WeakMap $closureTargets;

    public function __construct()
    {
        $this->closureTargets = new WeakMap();
    }

    public function create(ReflectionParameter $parameter): ParameterTarget
    {
        $function = $parameter->getDeclaringFunction();

        if ($function instanceof ReflectionFunction && $function->isClosure()) {
            $closure = $function->getClosure();
            $targets = $this->closureTargets[$closure] ?? [];
            $position = $parameter->getPosition();
            $target = $targets[$position] ??= new ParameterTarget($parameter);
            $this->closureTargets[$closure] = $targets;

            return $target;
        }

        $class = $parameter->getDeclaringClass()?->getName();
        $key = $class === null
            ? sprintf('function:%s:%d', $function->getName(), $parameter->getPosition())
            : sprintf('method:%s::%s:%d', $class, $function->getName(), $parameter->getPosition());

        return $this->namedTargets[$key] ??= new ParameterTarget($parameter);
    }
}

<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Target;

use ReflectionParameter;

use function Componenta\DI\Internal\is_closure_reflector;

/** Creates and reuses immutable parameter targets for stable named reflectors. */
final class ParameterTargetFactory
{
    /** @var array<string, ParameterTarget> */
    private array $namedTargets = [];

    public function create(ReflectionParameter $parameter): ParameterTarget
    {
        if ($parameter::class !== ReflectionParameter::class) {
            // Derived reflectors may supply instance-specific metadata and state.
            return new ParameterTarget($parameter);
        }

        $function = $parameter->getDeclaringFunction();

        if (is_closure_reflector($function)) {
            // Closure metadata belongs to an instance, not a stable method name.
            // Retaining its parameter reflector can also retain captured objects.
            return new ParameterTarget($parameter);
        }

        $class = $parameter->getDeclaringClass()?->getName();
        $key = $class === null
            ? sprintf('function:%s:%d', $function->getName(), $parameter->getPosition())
            : sprintf('method:%s::%s:%d', $class, $function->getName(), $parameter->getPosition());

        return $this->namedTargets[$key] ??= new ParameterTarget($parameter);
    }
}

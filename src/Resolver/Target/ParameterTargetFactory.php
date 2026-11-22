<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Target;

use Closure;
use Componenta\DI\Internal\Resolver\Parameter\ParameterAttributeObservation;
use ReflectionParameter;

use function Componenta\DI\Internal\is_closure_reflector;

/** Creates and reuses immutable parameter targets for stable named reflectors. */
final class ParameterTargetFactory
{
    /** @var array<string, ParameterTarget> */
    private array $namedTargets = [];
    private readonly ParameterAttributeObservation $observation;

    public function __construct()
    {
        $this->observation = new ParameterAttributeObservation();
    }

    public function create(ReflectionParameter $parameter): ParameterTarget
    {
        if ($parameter::class !== ReflectionParameter::class) {
            // Derived reflectors may supply instance-specific metadata and state.
            return new ParameterTarget($parameter, $this->observation);
        }

        $function = $parameter->getDeclaringFunction();

        if (is_closure_reflector($function)) {
            // Closure metadata belongs to an instance, not a stable method name.
            // Retaining its parameter reflector can also retain captured objects.
            return new ParameterTarget($parameter, $this->observation);
        }

        $class = $parameter->getDeclaringClass()?->getName();
        $key = $class === null
            ? sprintf('function:%s:%d', $function->getName(), $parameter->getPosition())
            : sprintf('method:%s::%s:%d', $class, $function->getName(), $parameter->getPosition());

        return $this->namedTargets[$key] ??= new ParameterTarget($parameter, $this->observation);
    }

    /**
     * @internal
     * @template T
     * @param Closure(ParameterTarget):T $classify
     * @return array{T,list<string>}
     */
    public function observeAttributeQueries(ParameterTarget $target, Closure $classify): array
    {
        return $this->observation->capture($target, $classify);
    }
}

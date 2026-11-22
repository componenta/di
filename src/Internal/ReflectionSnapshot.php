<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use LogicException;
use PropertyHookType;
use ReflectionClass;
use ReflectionMethod;
use ReflectionObject;
use ReflectionParameter;
use ReflectionProperty;

/** Detaches native reflectors from caches and from inputs owned by callers. @internal */
final class ReflectionSnapshot
{
    private function __construct() {}

    /**
     * @template T of object
     * @param ReflectionClass<covariant T> $class
     * @return ReflectionClass<covariant T>
     */
    public static function class(ReflectionClass $class): ReflectionClass
    {
        // ReflectionObject and derived reflectors can describe instance-specific metadata.
        return $class::class === ReflectionClass::class ? new ReflectionClass($class->getName()) : $class;
    }

    /**
     * Constructor invocation needs the class declaration of a native object reflector.
     * @template T of object
     * @param ReflectionClass<covariant T> $class
     * @return ReflectionClass<covariant T>
     */
    public static function forConstruction(ReflectionClass $class): ReflectionClass
    {
        return $class::class === ReflectionObject::class ? new ReflectionClass($class->getName()) : self::class($class);
    }

    public static function parameter(ReflectionParameter $parameter): ReflectionParameter
    {
        if ($parameter::class !== ReflectionParameter::class) {
            return $parameter;
        }

        // Obtain an independent parameter of the same native declaration, including
        // bound closures and instance-specific Closure::__invoke reflectors.
        return $parameter->getDeclaringFunction()->getParameters()[$parameter->getPosition()];
    }

    /**
     * @param ReflectionClass<covariant object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $target
     * @param ReflectionClass<covariant object>|null $lookupClass
     * @return ReflectionClass<covariant object>|ReflectionMethod|ReflectionParameter|ReflectionProperty
     */
    public static function target(
        ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target,
        ?ReflectionClass $lookupClass = null,
    ): ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty {
        if ($target instanceof ReflectionClass) {
            return self::forConstruction($target);
        }
        if ($target instanceof ReflectionParameter) {
            return self::parameter($target);
        }
        if ($target::class === ReflectionMethod::class && !is_closure_reflector($target)) {
            $owner = $target->getDeclaringClass();
            $name = $target->getName();
            if (str_starts_with($name, '$')) {
                [$property, $hook] = explode('::', substr($name, 1), 2);
                return $owner->getProperty($property)->getHook(PropertyHookType::from($hook))
                    ?? throw new LogicException('Expected the reflected property hook.');
            }
            if ($target->isStatic() && !$target->isPrivate() && $lookupClass?->isSubclassOf($owner->getName()) && $lookupClass->hasMethod($name)) {
                $method = $lookupClass->getMethod($name);
                if ($method->getDeclaringClass()->getName() === $owner->getName()) {
                    return $method;
                }
            }
            return $owner->getMethod($name);
        }
        if ($target::class === ReflectionProperty::class && $target->isDefault()) {
            return $target->getDeclaringClass()->getProperty($target->getName());
        }

        return $target;
    }
}

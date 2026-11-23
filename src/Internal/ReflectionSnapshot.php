<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Closure;
use Componenta\DI\Exception\AttributeCompositionException;
use LogicException;
use PropertyHookType;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionObject;
use ReflectionParameter;
use ReflectionProperty;
use Reflector;

/** Detaches native reflectors from caches and from inputs owned by callers. @internal */
final class ReflectionSnapshot
{
    private function __construct() {}

    /** @param ReflectionAttribute<object> $attribute */
    public static function assertNativeAttribute(ReflectionAttribute $attribute): void
    {
        if ($attribute::class !== ReflectionAttribute::class) {
            throw new AttributeCompositionException(sprintf(
                'Attribute plans require native ReflectionAttribute declarations. Received %s.',
                $attribute::class,
            ));
        }
    }

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
     * Owns the declaration used to allocate a reflected class.
     * @template T of object
     * @param ReflectionClass<covariant T> $class
     * @return ReflectionClass<covariant T>
     */
    public static function forConstruction(ReflectionClass $class): ReflectionClass
    {
        return new ReflectionClass($class->getName());
    }

    public static function parameter(ReflectionParameter $parameter, bool $forPublication = false): ReflectionParameter
    {
        if (!$forPublication && $parameter::class !== ReflectionParameter::class) {
            return $parameter;
        }

        // Obtain an independent parameter of the same native declaration, including
        // bound closures and instance-specific Closure::__invoke reflectors.
        return self::parameterFunction($parameter)->getParameters()[self::parameterPosition($parameter)];
    }

    /**
     * Checks whether a borrowed parameter reflector still supplies metadata for its owned declaration.
     * @phpstan-impure
     */
    public static function sameParameter(ReflectionParameter $source, ReflectionParameter $declaration): bool
    {
        if (self::parameterPosition($source) !== $declaration->getPosition()) {
            return false;
        }

        $actual = self::parameterFunction($source);
        $expected = $declaration->getDeclaringFunction();
        if ($actual::class !== $expected::class || $actual->getName() !== $expected->getName()) {
            return false;
        }

        if ($actual instanceof ReflectionMethod && $expected instanceof ReflectionMethod) {
            // The instance-specific Closure::__invoke reflector does not expose its closure.
            return !is_closure_reflector($actual)
                && $actual->getDeclaringClass()->getName() === $expected->getDeclaringClass()->getName();
        }

        if ($actual instanceof ReflectionFunction && $expected instanceof ReflectionFunction && $actual->isClosure()) {
            return $expected->isClosure() && $actual->getClosure() === $expected->getClosure();
        }

        return true;
    }

    /** @param ReflectionClass<covariant object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $source */
    public static function hasBorrowedMetadata(ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $source): bool
    {
        return match ($source::class) {
            ReflectionClass::class, ReflectionMethod::class, ReflectionParameter::class, ReflectionProperty::class => false,
            default => true,
        };
    }

    /**
     * A caller-owned reflector can change its declaration between metadata reads.
     * @phpstan-impure
     * @param ReflectionClass<covariant object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $source
     * @param ReflectionClass<covariant object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $declaration
     */
    public static function sameDeclaration(
        ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $source,
        ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $declaration,
    ): bool {
        if ($source instanceof ReflectionClass && $declaration instanceof ReflectionClass) {
            return $source->getName() === $declaration->getName();
        }
        if ($source instanceof ReflectionParameter && $declaration instanceof ReflectionParameter) {
            return self::sameParameter($source, $declaration);
        }
        if (($source instanceof ReflectionMethod && $declaration instanceof ReflectionMethod)
            || ($source instanceof ReflectionProperty && $declaration instanceof ReflectionProperty && self::isDeclaredProperty($source))
        ) {
            [$owner, $name] = self::memberDeclaration($source);
            return $name === $declaration->getName() && $owner->getName() === $declaration->getDeclaringClass()->getName();
        }
        return false;
    }

    /**
     * @param Closure|ReflectionClass<covariant object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $target
     */
    public static function assertPlanTarget(
        Closure|ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target,
    ): void {
        if ($target instanceof ReflectionMethod) {
            [$owner, $name] = self::memberDeclaration($target);
            if ($name === '__invoke' && $owner->getName() === Closure::class) {
                throw new AttributeCompositionException('Pass the Closure object to plan attributes on Closure::__invoke.');
            }
        }
        if ($target instanceof ReflectionProperty && !self::isDeclaredProperty($target)) {
            throw new AttributeCompositionException('An attribute plan requires a declared property.');
        }
    }

    /**
     * @param Closure|ReflectionClass<covariant object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $target
     * @return ($target is Closure ? Closure : ReflectionClass<covariant object>|ReflectionMethod|ReflectionParameter|ReflectionProperty)
     */
    public static function planDeclaration(
        Closure|ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target,
    ): Closure|ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty {
        self::assertPlanTarget($target);
        // Keep the closure itself so each read can reconstruct its invocation method.
        return $target instanceof Closure ? $target : self::target($target, forPublication: true);
    }

    /**
     * @param Closure|ReflectionClass<covariant object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $target
     * @param ReflectionClass<covariant object>|null $lookupClass
     * @return ReflectionClass<covariant object>|ReflectionMethod|ReflectionParameter|ReflectionProperty
     */
    public static function target(
        Closure|ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target,
        ?ReflectionClass $lookupClass = null,
        bool $forPublication = false,
    ): ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty {
        if ($target instanceof Closure) {
            return new ReflectionMethod($target, '__invoke');
        }
        if ($target instanceof ReflectionClass) {
            return $forPublication || $target::class === ReflectionObject::class
                ? self::forConstruction($target)
                : self::class($target);
        }
        if ($target instanceof ReflectionParameter) {
            return self::parameter($target, $forPublication);
        }
        if ($target instanceof ReflectionMethod && ($forPublication || $target::class === ReflectionMethod::class)) {
            [$owner, $name] = self::memberDeclaration($target);
            if ($name === '__invoke' && $owner->getName() === Closure::class) {
                return $target;
            }
            if (str_starts_with($name, '$')) {
                [$property, $hook] = explode('::', substr($name, 1), 2);
                return $owner->getProperty($property)->getHook(PropertyHookType::from($hook))
                    ?? throw new LogicException('Expected the reflected property hook.');
            }
            if ($lookupClass !== null && $target->isStatic() && !$target->isPrivate() && $lookupClass->isSubclassOf($owner->getName()) && $lookupClass->hasMethod($name)) {
                $method = $lookupClass->getMethod($name);
                if ($method->getDeclaringClass()->getName() === $owner->getName()) {
                    return $method;
                }
            }
            return $owner->getMethod($name);
        }
        if ($target instanceof ReflectionProperty
            && ($forPublication || $target::class === ReflectionProperty::class)
            && ($forPublication || $target->isDefault())
        ) {
            [$owner, $name] = self::memberDeclaration($target);
            return $owner->getProperty($name);
        }

        return $target;
    }

    private static function parameterFunction(ReflectionParameter $parameter): ReflectionFunctionAbstract
    {
        if ($parameter::class === ReflectionParameter::class) {
            return $parameter->getDeclaringFunction();
        }

        /** @var ReflectionFunctionAbstract $function */
        $function = self::nativeValue($parameter, ReflectionParameter::class, 'getDeclaringFunction');
        return $function;
    }

    private static function parameterPosition(ReflectionParameter $parameter): int
    {
        if ($parameter::class === ReflectionParameter::class) {
            return $parameter->getPosition();
        }

        /** @var int $position */
        $position = self::nativeValue($parameter, ReflectionParameter::class, 'getPosition');
        return $position;
    }

    /** @return array{ReflectionClass<object>,string} */
    private static function memberDeclaration(ReflectionMethod|ReflectionProperty $member): array
    {
        $base = $member instanceof ReflectionMethod ? ReflectionMethod::class : ReflectionProperty::class;
        if ($member::class === $base) {
            return [$member->getDeclaringClass(), $member->getName()];
        }

        /** @var ReflectionClass<object> $owner */
        $owner = self::nativeValue($member, $base, 'getDeclaringClass');
        /** @var string $name */
        $name = self::nativeValue($member, $base, 'getName');
        return [$owner, $name];
    }

    private static function isDeclaredProperty(ReflectionProperty $property): bool
    {
        return $property::class === ReflectionProperty::class
            ? $property->isDefault()
            : self::nativeValue($property, ReflectionProperty::class, 'isDefault') === true;
    }

    /** @param class-string $base */
    private static function nativeValue(Reflector $reflector, string $base, string $method): mixed
    {
        /** @var array<class-string,array<string,ReflectionMethod>> $readers */
        static $readers = [];
        return ($readers[$base][$method] ??= new ReflectionMethod($base, $method))->invoke($reflector);
    }
}

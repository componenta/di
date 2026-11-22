<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Entry;

use Closure;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use ReflectionClass;
use ReflectionProperty;

/** Dispatches handlers through the context's phase authority. @internal */
abstract class ObjectCreationAccess extends ObjectCreationScope
{
    /**
     * @template T of object
     * @param ReflectionClass<T> $class
     * @param array<string|int,mixed>|Closure():array<string|int,mixed> $parameters
     * @param (Closure(ObjectCreationContext):void)|null $beforeParameterRead
     */
    public static function begin(ReflectionClass $class, array|Closure $parameters = [], ?Closure $beforeParameterRead = null): ObjectCreationAttempt
    {
        return ObjectCreationContext::startAttempt($class, $parameters, $beforeParameterRead);
    }

    /**
     * @template T
     * @param Closure():T $dispatch
     * @return T
     */
    public static function dispatch(ObjectCreationContext $context, AttributePhase $phase, Closure $dispatch): mixed
    {
        return $context->runPhase($phase, $dispatch);
    }

    public static function recheckParameters(ObjectCreationContext $context): void
    {
        $context->assertParametersReadable();
    }

    public static function requireOpenProperties(ObjectCreationContext $context): void
    {
        $context->assertPropertyWritesOpen();
    }

    /** @param Closure():void $resolve */
    public static function withinProperty(ObjectCreationContext $context, ReflectionProperty $property, Closure $resolve): void
    {
        $context->resolveProperty($property, $resolve);
    }
}

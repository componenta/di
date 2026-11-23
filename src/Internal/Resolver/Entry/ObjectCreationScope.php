<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Entry;

use Closure;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use ReflectionClass;
use ReflectionProperty;

/** Operations reserved for phase dispatch and validation. @internal */
abstract class ObjectCreationScope
{
    /**
     * @template T of object
     * @param ReflectionClass<T> $class
     * @param array<string|int,mixed>|Closure():array<string|int,mixed> $parameters
     * @param (Closure(ObjectCreationContext):void)|null $beforeParameterRead
     */
    abstract protected static function startAttempt(ReflectionClass $class, array|Closure $parameters, ?Closure $beforeParameterRead): ObjectCreationAttempt;

    /**
     * @template T
     * @param Closure():T $dispatch
     * @return T
     */
    abstract protected function runPhase(AttributePhase $phase, Closure $dispatch): mixed;

    abstract protected function findParameter(string $name, mixed &$value): bool;

    abstract protected function assertParametersReadable(): void;

    abstract protected function assertPropertyWritesOpen(): void;

    /** @param Closure():void $resolve */
    abstract protected function resolveProperty(ReflectionProperty $property, Closure $resolve): void;
}

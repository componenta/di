<?php

declare(strict_types=1);

namespace Componenta\DI;

use Componenta\Reflection\Reflection;
use ReflectionClass;

/**
 * Default {@see ProxyFactoryInterface} implementation backed by PHP 8.4
 * native lazy objects.
 *
 * - {@see makeLazy()} delegates to {@see ReflectionClass::newLazyGhost()}.
 * - {@see makeProxy()} delegates to {@see ReflectionClass::newLazyProxy()}.
 *
 * The native lazy-object API works with `final` and `readonly` classes
 * and incurs only the cost of a single reflection construction per call -
 * no bytecode generation, no userland proxy classes.
 */
final readonly class ProxyFactory implements ProxyFactoryInterface
{
    /**
     * @throws \ReflectionException
     */
    public function makeLazy(string $class, callable $initializer): object
    {
        return Reflection::class($class)->newLazyGhost($initializer);
    }

    /**
     * @throws \ReflectionException
     */
    public function makeProxy(string $class, callable $factory): object
    {
        return Reflection::class($class)->newLazyProxy($factory);
    }
}

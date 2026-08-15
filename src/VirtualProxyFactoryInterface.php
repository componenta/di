<?php

declare(strict_types=1);

namespace Componenta\DI;

use ReflectionClass;

/** Produces native virtual proxies for a target class. */
interface VirtualProxyFactoryInterface
{
    /**
     * @template T of object
     * @param class-string<T> $class
     * @param callable(T $proxy): T $factory
     * @return T
     *
     * @see ReflectionClass::newLazyProxy()
     */
    public function makeProxy(string $class, callable $factory): object;
}

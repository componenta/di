<?php

declare(strict_types=1);

namespace Componenta\DI;

use Componenta\DI\Exception\ExceptionInterface;
use ReflectionClass;

/** Produces native virtual proxies for a target class. */
interface VirtualProxyFactoryInterface
{
    /**
     * @template T of object
     * @param class-string<T> $class
     * @param callable(T $proxy): T $factory
     * @return T
     * @throws ExceptionInterface If the proxy cannot be created by DI.
     *
     * The backing factory is deferred. Throwables raised by a caller-supplied
     * factory later are owned by that callback unless it re-enters a DI
     * resolution boundary.
     *
     * @see ReflectionClass::newLazyProxy()
     */
    public function makeProxy(string $class, callable $factory): object;
}

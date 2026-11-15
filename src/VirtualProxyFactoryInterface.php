<?php

declare(strict_types=1);

namespace Componenta\DI;

use Componenta\DI\Exception\ExceptionInterface;
use ReflectionClass;

/** Produces virtual proxies for a target class. */
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
     * The default {@see ProxyFactory} requires a non-static backed instance
     * property, possibly inherited. A native-eligible target without one
     * fails before allocation with {@see \Componenta\DI\Exception\ResolutionException};
     * use ordinary eager construction instead. Other native restrictions
     * retain PHP's failure as the previous exception. This property
     * requirement belongs to the default adapter; custom implementations
     * may support other mechanisms while preserving the deferred contract.
     *
     * @see ReflectionClass::newLazyProxy()
     */
    public function makeProxy(string $class, callable $factory): object;
}

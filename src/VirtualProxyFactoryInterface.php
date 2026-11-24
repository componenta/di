<?php

declare(strict_types=1);

namespace Componenta\DI;

use Componenta\DI\Exception\ExceptionInterface;
use ReflectionClass;

/** Produces virtual proxies for a target class. */
interface VirtualProxyFactoryInterface
{
    /**
     * @template TProxy of object
     * @param class-string<TProxy> $class
     * @param callable(TProxy $proxy): object $factory
     * @return TProxy
     * @throws ExceptionInterface If the proxy cannot be created by DI.
     *
     * The backing factory is deferred. Throwables raised by a caller-supplied
     * factory later are owned by that callback unless it re-enters a DI
     * resolution boundary.
     *
     * The default {@see ProxyFactory} requires a non-static backed instance
     * property, possibly inherited. A native-eligible target without one
     * fails before allocation with {@see \Componenta\DI\Exception\ResolutionException};
     * use ordinary eager construction instead. Other native allocation restrictions
     * retain PHP's failure as the previous exception. The backing object must
     * also satisfy PHP's native requirements: it must not be lazy or the proxy
     * itself, and its class must be the proxy class or a parent whose proxy subclass
     * adds no properties and does not override __destruct() or __clone(). Native
     * backing validation happens during initialization and retains its PHP error.
     * These requirements belong to the default adapter; custom implementations
     * may support other mechanisms while preserving the deferred contract.
     *
     * @see ReflectionClass::newLazyProxy()
     */
    public function makeProxy(string $class, callable $factory): object;
}

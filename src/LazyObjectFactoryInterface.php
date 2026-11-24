<?php

declare(strict_types=1);

namespace Componenta\DI;

use Componenta\DI\Exception\ExceptionInterface;
use ReflectionClass;

/** Produces lazy objects for a target class. */
interface LazyObjectFactoryInterface
{
    /**
     * @template T of object
     * @param class-string<T> $class
     * @param callable(T $instance): void $initializer
     * @return T
     * @throws ExceptionInterface If the lazy object cannot be created by DI.
     *
     * The initializer is deferred. Throwables raised by a caller-supplied
     * initializer later are owned by that callback unless it re-enters a DI
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
     * @see ReflectionClass::newLazyGhost()
     */
    public function makeLazy(string $class, callable $initializer): object;
}

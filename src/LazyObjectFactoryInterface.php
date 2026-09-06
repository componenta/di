<?php

declare(strict_types=1);

namespace Componenta\DI;

use Componenta\DI\Exception\ExceptionInterface;
use ReflectionClass;

/** Produces native lazy objects for a target class. */
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
     * @see ReflectionClass::newLazyGhost()
     */
    public function makeLazy(string $class, callable $initializer): object;
}

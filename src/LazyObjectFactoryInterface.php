<?php

declare(strict_types=1);

namespace Componenta\DI;

use ReflectionClass;

/** Produces native lazy objects for a target class. */
interface LazyObjectFactoryInterface
{
    /**
     * @template T of object
     * @param class-string<T> $class
     * @param callable(T $instance): mixed $initializer
     * @return T
     *
     * @see ReflectionClass::newLazyGhost()
     */
    public function makeLazy(string $class, callable $initializer): object;
}

<?php

declare(strict_types=1);

namespace Componenta\DI;

use ReflectionClass;

/**
 * Produces lazy objects - instances of the target class whose state is
 * filled in place on first observable access.
 *
 * The returned object is of class `$class`: `get_class()`, `instanceof`,
 * `static::class`, type hints, and identity checks all report the target
 * class. The wrapper is indistinguishable from a normally constructed
 * instance once it has been initialized.
 *
 * Reference implementation on PHP 8.4: {@see ReflectionClass::newLazyGhost()}.
 */
interface LazyObjectFactoryInterface
{
    /**
     * Wraps `$class` in a lazy-initialized instance.
     *
     * The initializer is invoked on first observable access. What
     * constitutes an observable access is implementation-defined but is
     * expected to match normal PHP behaviour: reading or writing a
     * property, calling a method, serialization, `clone`, and similar
     * operations.
     *
     * The initializer receives the uninitialized instance and must mutate
     * it in place - typically by invoking its constructor:
     *
     * ```php
     * $factory->makeLazy(
     *     Foo::class,
     *     fn (object $instance) => $instance->__construct($dep1, $dep2),
     * );
     * ```
     *
     * Any value returned from the initializer is ignored. Side effects of
     * the constructor (listener registration, hook installation, resource
     * acquisition) apply to the wrapper instance itself, which is what
     * consumers hold.
     *
     * @template T of object
     *
     * @param class-string<T>                    $class       Target class.
     * @param callable(object $instance): mixed  $initializer Mutates the
     *     received uninitialized instance in place. Return value is ignored.
     *
     * @return T An instance of `$class`.
     */
    public function makeLazy(string $class, callable $initializer): object;
}

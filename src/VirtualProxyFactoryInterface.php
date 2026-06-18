<?php

declare(strict_types=1);

namespace Componenta\DI;

use ReflectionClass;

/**
 * Produces virtual proxies - subtype instances that forward all operations
 * to a real backing object supplied by the factory on first access.
 *
 * `instanceof $class` and type hints against `$class` work transparently;
 * `get_class()` reports the generated proxy class name, not `$class`.
 *
 * Use this when the backing instance must come from an opaque factory
 * callable, can be a subclass, can be a decorator, or otherwise cannot
 * be produced by mutating a pre-allocated target object.
 *
 * Reference implementation on PHP 8.4: {@see ReflectionClass::newLazyProxy()}.
 */
interface VirtualProxyFactoryInterface
{
    /**
     * Wraps `$class` in a virtual proxy that forwards to a real backing
     * instance supplied by `$factory` on first observable access.
     *
     * The factory receives the proxy (provided for signature uniformity,
     * normally unused) and must return the real backing instance - any
     * object that is-a `$class`, including subclasses and decorators:
     *
     * ```php
     * $proxyFactory->makeProxy(
     *     Foo::class,
     *     fn (object $proxy): Foo => $applicationFactory($container),
     * );
     * ```
     *
     * @template T of object
     *
     * @param class-string<T>                    $class    Target class.
     * @param callable(object $proxy): object    $factory  Returns the real
     *     backing instance; must be an instance of `$class` or a subtype.
     *
     * @return T A virtual-proxy instance that is-a `$class`.
     */
    public function makeProxy(string $class, callable $factory): object;
}

<?php

declare(strict_types=1);

namespace Componenta\DI;

use ReflectionClass;

/**
 * Default {@see ProxyFactoryInterface} implementation backed by PHP 8.4
 * native lazy objects.
 *
 * - {@see makeLazy()} delegates to {@see ReflectionClass::newLazyGhost()}.
 * - {@see makeProxy()} delegates to {@see ReflectionClass::newLazyProxy()}.
 */
final readonly class ProxyFactory implements ProxyFactoryInterface
{
    /**
     * @template T of object
     * @param class-string<T> $class
     * @param callable(T): void $initializer
     * @return T
     * @throws \ReflectionException
     */
    public function makeLazy(string $class, callable $initializer): object
    {
        /** @var ReflectionClass<T> $reflection */
        $reflection = new ReflectionClass($class);

        return $reflection->newLazyGhost($initializer);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param callable(T): T $factory
     * @return T
     * @throws \ReflectionException
     */
    public function makeProxy(string $class, callable $factory): object
    {
        /** @var ReflectionClass<T> $reflection */
        $reflection = new ReflectionClass($class);

        return $reflection->newLazyProxy($factory);
    }
}

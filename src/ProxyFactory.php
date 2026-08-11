<?php

declare(strict_types=1);

namespace Componenta\DI;

use ReflectionClass;

/** Default PHP 8.4 native lazy-object and virtual-proxy factory. */
final readonly class ProxyFactory implements ProxyFactoryInterface
{
    /**
     * @template T of object
     * @param class-string<T> $class
     * @param callable(T): mixed $initializer
     * @return T
     * @throws \ReflectionException
     */
    public function makeLazy(string $class, callable $initializer): object
    {
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
        $reflection = new ReflectionClass($class);

        return $reflection->newLazyProxy($factory);
    }
}

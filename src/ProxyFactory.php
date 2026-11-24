<?php

declare(strict_types=1);

namespace Componenta\DI;

use Componenta\DI\Exception\ExceptionInterface;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Internal\ResolutionMetadata;
use ReflectionClass;
use Throwable;

/** Default {@see ProxyFactoryInterface} implementation backed by PHP native lazy objects. */
final readonly class ProxyFactory implements ProxyFactoryInterface
{
    /**
     * @template T of object
     * @param class-string<T> $class
     * @param callable(T): void $initializer
     * @return T
     */
    public function makeLazy(string $class, callable $initializer): object
    {
        try {
            if (is_array($initializer)) {
                $initializer = ResolutionMetadata::snapshot($initializer);
            }
            /** @var ReflectionClass<T> $reflection */
            $reflection = new ReflectionClass($class);
            return $reflection->newLazyGhost($initializer);
        } catch (ExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forService($class, $e);
        }
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param callable(T): T $factory
     * @return T
     */
    public function makeProxy(string $class, callable $factory): object
    {
        try {
            if (is_array($factory)) {
                $factory = ResolutionMetadata::snapshot($factory);
            }
            /** @var ReflectionClass<T> $reflection */
            $reflection = new ReflectionClass($class);
            return $reflection->newLazyProxy($factory);
        } catch (ExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forService($class, $e);
        }
    }
}

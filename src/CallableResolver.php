<?php

declare(strict_types=1);

namespace Componenta\DI;

use Componenta\DI\Exception\InvalidCallableException;
use Psr\Container\ContainerInterface;

/**
 * Resolves various formats into PHP callables.
 *
 * String specifications are interpreted as opaque PSR-11 ids before native
 * PHP callable syntax. This matters for ids such as `strlen` or `Foo::bar`:
 * when the container owns such an id, the registered service wins.
 */
class CallableResolver implements CallableResolverInterface
{
    /** @var array<string, bool> Cache: "Class::method" => isStatic */
    private array $staticCache = [];

    public function __construct(
        protected readonly ContainerInterface $container,
    ) {}

    public function resolve(mixed $callable): callable
    {
        if ($callable instanceof \Closure) {
            return $callable;
        }

        // Strings and string-owned method arrays have DI semantics and must be
        // resolved before PHP's is_callable() can reinterpret them as native
        // functions/static methods.
        if (is_string($callable)) {
            return $this->resolveString($callable);
        }

        if (is_array($callable)) {
            return $this->resolveArray($callable);
        }

        if (is_callable($callable)) {
            return $callable;
        }

        throw InvalidCallableException::forValue($callable);
    }

    protected function resolveString(string $callable): callable
    {
        // PSR-11 ids are opaque. A registered/resolvable id wins even when it
        // contains syntax such as "::" or matches a native PHP function.
        if ($this->container->has($callable)) {
            $entry = $this->container->get($callable);
            if (is_callable($entry)) {
                return $entry;
            }

            throw InvalidCallableException::forNonInvokable($callable);
        }

        if (str_contains($callable, '::')) {
            return $this->resolveClassMethod($callable);
        }

        if (function_exists($callable)) {
            return $callable;
        }

        if (class_exists($callable) || interface_exists($callable)) {
            throw InvalidCallableException::forMissingService($callable);
        }

        throw InvalidCallableException::forValue($callable);
    }

    protected function resolveClassMethod(string $callable): callable
    {
        [$class, $method] = explode('::', $callable, 2);

        if (!class_exists($class) && !interface_exists($class)) {
            throw InvalidCallableException::forValue($callable);
        }

        if (!method_exists($class, $method)) {
            throw InvalidCallableException::forMethod($class, $method);
        }

        if ($this->isStaticMethod($class, $method)) {
            if (is_callable([$class, $method])) {
                return [$class, $method];
            }

            throw InvalidCallableException::forMethod($class, $method);
        }

        if ($this->container->has($class)) {
            $entry = $this->container->get($class);
            if (is_object($entry) && is_callable([$entry, $method])) {
                return [$entry, $method];
            }

            throw InvalidCallableException::forMethod($class, $method);
        }

        throw InvalidCallableException::forMissingService($class);
    }

    /** @param array<mixed> $callable */
    protected function resolveArray(array $callable): callable
    {
        if (count($callable) !== 2) {
            throw InvalidCallableException::forValue($callable);
        }

        [$objectOrClass, $method] = $callable;

        if (!is_string($method) || $method === '') {
            throw InvalidCallableException::forValue($callable);
        }

        if (is_object($objectOrClass)) {
            if (is_callable([$objectOrClass, $method])) {
                return [$objectOrClass, $method];
            }

            throw InvalidCallableException::forMethod($objectOrClass::class, $method);
        }

        if (!is_string($objectOrClass) || $objectOrClass === '') {
            throw InvalidCallableException::forValue($callable);
        }

        // The first tuple element can itself be an opaque service id. Resolve
        // it before treating a real class name as a native static callable.
        if ($this->container->has($objectOrClass)) {
            $entry = $this->container->get($objectOrClass);
            if (is_object($entry) && is_callable([$entry, $method])) {
                return [$entry, $method];
            }

            throw InvalidCallableException::forMethod($objectOrClass, $method);
        }

        if (!class_exists($objectOrClass) && !interface_exists($objectOrClass)) {
            throw InvalidCallableException::forValue($callable);
        }

        if (!method_exists($objectOrClass, $method)) {
            throw InvalidCallableException::forMethod($objectOrClass, $method);
        }

        if ($this->isStaticMethod($objectOrClass, $method)) {
            if (is_callable([$objectOrClass, $method])) {
                return [$objectOrClass, $method];
            }

            throw InvalidCallableException::forMethod($objectOrClass, $method);
        }

        throw InvalidCallableException::forMissingService($objectOrClass);
    }

    /** @throws \ReflectionException */
    private function isStaticMethod(string $class, string $method): bool
    {
        $key = $class . '::' . $method;

        if (!array_key_exists($key, $this->staticCache)) {
            $this->staticCache[$key] = new \ReflectionMethod($class, $method)->isStatic();
        }

        return $this->staticCache[$key];
    }
}

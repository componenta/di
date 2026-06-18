<?php

declare(strict_types=1);

namespace Componenta\DI;

use Componenta\DI\Exception\InvalidCallableException;
use Psr\Container\ContainerInterface;

/**
 * Resolves various formats into PHP callables.
 *
 * Supports multiple callable formats:
 * - Closure: returned as-is
 * - Native callable (array/invokable): wrapped with first-class callable syntax
 * - String with `::`: static method or instance method via container
 * - String (service ID): fetched from container if callable
 * - Array [class, method]: instance fetched from container
 *
 * Performance optimizations:
 * - Static method reflection results are cached
 * - Reflection is only used when necessary (static vs instance check)
 * - Early returns to avoid unnecessary checks
 *
 * The container is mandatory and supplied through the constructor; supply
 * {@see NullContainer} if no real lookup is needed at the time of construction.
 *
 * @example Static method
 * ```php
 * $callable = $resolver->resolve('MyClass::staticMethod');
 * ```
 *
 * @example Instance method (requires container)
 * ```php
 * $callable = $resolver->resolve('MyService::handle');
 * $callable = $resolver->resolve([MyService::class, 'handle']);
 * ```
 *
 * @example Invokable service
 * ```php
 * $callable = $resolver->resolve(MyHandler::class);
 * ```
 *
 * @throws InvalidCallableException If callable cannot be resolved.
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
        // Closure - return as-is
        if ($callable instanceof \Closure) {
            return $callable;
        }

        // Already callable - wrap and return
        if (is_callable($callable)) {
            return $callable(...);
        }

        // String - resolve class::method, service, or function
        if (is_string($callable)) {
            return $this->resolveString($callable);
        }

        // Array - resolve [class, method]
        if (is_array($callable)) {
            return $this->resolveArray($callable);
        }

        throw InvalidCallableException::forValue($callable);
    }

    protected function resolveString(string $callable): callable
    {
        // Class::method format
        if (str_contains($callable, '::')) {
            return $this->resolveClassMethod($callable);
        }

        // Try container first (invokable service)
        if ($this->container->has($callable)) {
            $entry = $this->container->get($callable);
            if (is_callable($entry)) {
                return $entry(...);
            }

            throw InvalidCallableException::forNonInvokable($callable);
        }

        // Global function
        if (function_exists($callable)) {
            return $callable(...);
        }

        // Class name - check if invokable
        if (class_exists($callable)) {
            throw InvalidCallableException::forMissingService($callable);
        }

        throw InvalidCallableException::forValue($callable);
    }

    protected function resolveClassMethod(string $callable): callable
    {
        [$class, $method] = explode('::', $callable, 2);

        if (!class_exists($class)) {
            throw InvalidCallableException::forValue($callable);
        }

        if (!method_exists($class, $method)) {
            throw InvalidCallableException::forMethod($class, $method);
        }

        // Static method - no container needed
        if ($this->isStaticMethod($class, $method)) {
            return [$class, $method](...);
        }

        // Instance method - need container
        if ($this->container->has($class)) {
            $entry = $this->container->get($class);
            if (is_object($entry)) {
                return [$entry, $method](...);
            }
        }

        throw InvalidCallableException::forMissingService($class);
    }

    protected function resolveArray(array $callable): callable
    {
        if (count($callable) !== 2) {
            throw InvalidCallableException::forValue($callable);
        }

        [$objectOrClass, $method] = $callable;

        // Object instance - direct resolution
        if (is_object($objectOrClass)) {
            if (method_exists($objectOrClass, $method)) {
                return $callable(...);
            }
            throw InvalidCallableException::forMethod($objectOrClass::class, $method);
        }

        // Class string - fetch from container
        if (is_string($objectOrClass)) {
            if (!class_exists($objectOrClass)) {
                throw InvalidCallableException::forValue($callable);
            }

            if (!method_exists($objectOrClass, $method)) {
                throw InvalidCallableException::forMethod($objectOrClass, $method);
            }

            // Static method
            if ($this->isStaticMethod($objectOrClass, $method)) {
                return $callable(...);
            }

            // Instance method - need container
            if ($this->container->has($objectOrClass)) {
                $entry = $this->container->get($objectOrClass);
                if (is_object($entry)) {
                    return [$entry, $method](...);
                }
            }

            throw InvalidCallableException::forMissingService($objectOrClass);
        }

        throw InvalidCallableException::forValue($callable);
    }

    /**
     * Checks if method is static with caching.
     */
    private function isStaticMethod(string $class, string $method): bool
    {
        $key = $class . '::' . $method;

        if (!isset($this->staticCache[$key])) {
            $this->staticCache[$key] = (new \ReflectionMethod($class, $method))->isStatic();
        }

        return $this->staticCache[$key];
    }
}
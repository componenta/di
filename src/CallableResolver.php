<?php

declare(strict_types=1);

namespace Componenta\DI;

use Componenta\DI\Exception\ExceptionInterface;
use Componenta\DI\Exception\InvalidCallableException;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Resolves various formats into PHP callables.
 *
 * String specifications are interpreted as opaque PSR-11 ids before native
 * PHP callable syntax. Already-valid non-string callables retain native PHP
 * precedence.
 */
class CallableResolver implements CallableResolverInterface
{
    /** @var array<string, bool> */
    private array $staticCache = [];

    public function __construct(protected readonly ContainerInterface $container) {}

    public function resolve(mixed $callable): callable
    {
        try {
            if ($callable instanceof \Closure) {
                return $callable;
            }

            if (is_string($callable)) {
                return $this->resolveString($callable);
            }
    
            if (is_callable($callable)) {
                return $callable;
            }
    
            if (is_array($callable)) {
                return $this->resolveArray($callable);
            }

            throw InvalidCallableException::forValue($callable);
            
        } catch (ExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw InvalidCallableException::forValue($callable, $e);
        }
    }

    protected function resolveString(string $callable): callable
    {
        if ($this->container->has($callable)) {
            $entry = $this->container->get($callable);
            if (is_callable($entry)) {
                return $entry;
            }
            throw InvalidCallableException::forNonInvokable($callable);
        }

        if (str_contains($callable, '::')) {
            if (is_callable($callable)) {
                return $callable;
            }
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
            throw InvalidCallableException::forMethod($objectOrClass, $method);
        }

        throw InvalidCallableException::forMissingService($objectOrClass);
    }

    private function isStaticMethod(string $class, string $method): bool
    {
        $key = $class . '::' . $method;
        if (!array_key_exists($key, $this->staticCache)) {
            $this->staticCache[$key] = new \ReflectionMethod($class, $method)->isStatic();
        }
        return $this->staticCache[$key];
    }
}

<?php

declare(strict_types=1);

namespace Componenta\DI;

use Componenta\DI\Exception\ExceptionInterface;
use Componenta\DI\Exception\InvalidCallableException;
use Error;
use Psr\Container\ContainerInterface;
use Throwable;

use function Componenta\DI\Internal\is_abstract_static_dispatch_error;

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

            if (is_array($callable)) {
                $callable = self::snapshotCallable($callable);
                return $this->isNativeCallable($callable) ? $callable : $this->resolveArray($callable);
            }

            if (is_callable($callable)) {
                return $callable;
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
            if (is_array($entry)) {
                $entry = self::snapshotCallable($entry);
            }
            if (is_callable($entry)) {
                return $entry;
            }
            throw InvalidCallableException::forNonInvokable($callable);
        }

        if (str_contains($callable, '::')) {
            if ($this->isNativeCallable($callable)) {
                return $callable;
            }
            return $this->resolveClassMethod($callable);
        }

        if (function_exists($callable)) {
            return $callable;
        }

        if (self::classOrInterfaceExists($callable)) {
            throw InvalidCallableException::forMissingService($callable);
        }

        throw InvalidCallableException::forValue($callable);
    }

    protected function resolveClassMethod(string $callable): callable
    {
        [$class, $method] = explode('::', $callable, 2);

        if ($method === '') {
            throw InvalidCallableException::forValue($callable);
        }
        if (!class_exists($class, false) && !interface_exists($class, false)) {
            if (!self::classOrInterfaceExists($class)) {
                throw InvalidCallableException::forValue($callable);
            }
            // Discovery can reveal a native static route ahead of the instance service.
            // Qualified names keep their existing receiver selection.
            if (!str_contains($method, '::') && $this->isNativeCallable($callable)) {
                return $callable;
            }
        }
        if ($this->container->has($class)) {
            $entry = $this->container->get($class);
            if (is_object($entry) && is_callable([$entry, $method])) {
                return [$entry, $method];
            }
            throw InvalidCallableException::forMethod($class, $method);
        }

        if (!method_exists($class, $method)) {
            throw InvalidCallableException::forMethod($class, $method);
        }
        if ($this->isStaticMethod($class, $method)) {
            throw InvalidCallableException::forMethod($class, $method);
        }
        throw InvalidCallableException::forMissingService($class);
    }

    /** @param array<mixed> $callable */
    protected function resolveArray(array $callable): callable
    {
        if (count($callable) !== 2 || !array_key_exists(0, $callable) || !array_key_exists(1, $callable)) {
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

        $unqualifiedMethod = !str_contains($method, '::');
        $canDiscoverNative = $unqualifiedMethod && !class_exists($objectOrClass, false);
        if ($this->container->has($objectOrClass)) {
            // Availability checks may load the native owner before instantiation.
            if ($canDiscoverNative && class_exists($objectOrClass, false) && $this->isNativeCallable($callable)) {
                return $callable;
            }
            $entry = $this->container->get($objectOrClass);
            if (is_object($entry) && is_callable([$entry, $method])) {
                return [$entry, $method];
            }
            throw InvalidCallableException::forMethod($objectOrClass, $method);
        }

        if (!self::classOrInterfaceExists($objectOrClass)) {
            throw InvalidCallableException::forValue($callable);
        }
        if ($unqualifiedMethod && $this->isNativeCallable($callable)) {
            return $callable;
        }
        if (!method_exists($objectOrClass, $method)) {
            throw InvalidCallableException::forMethod($objectOrClass, $method);
        }
        if ($this->isStaticMethod($objectOrClass, $method)) {
            throw InvalidCallableException::forMethod($objectOrClass, $method);
        }

        throw InvalidCallableException::forMissingService($objectOrClass);
    }

    /** @phpstan-assert-if-true callable $candidate */
    private function isNativeCallable(mixed $candidate): bool
    {
        try {
            return is_callable($candidate);
        } catch (Error $error) {
            if (!is_abstract_static_dispatch_error($error, __FILE__)) {
                throw $error;
            }
            return false;
        }
    }

    private static function classOrInterfaceExists(string $name): bool
    {
        // The interface probe may autoload a class even though it returns false.
        return class_exists($name) || interface_exists($name) || class_exists($name, false);
    }

    /**
     * @param array<array-key,mixed> $callable
     * @return array<array-key,mixed>
     */
    private static function snapshotCallable(array $callable): array
    {
        // Validation may autoload application code that changes referenced
        // slots. Keep the selected route through validation and return.
        $snapshot = [];
        foreach ($callable as $key => $value) {
            $snapshot[$key] = $value;
        }
        return $snapshot;
    }

    private function isStaticMethod(string $class, string $method): bool
    {
        $key = strtolower($class . '::' . $method);
        if (!array_key_exists($key, $this->staticCache)) {
            $this->staticCache[$key] = new \ReflectionMethod($class, $method)->isStatic();
        }
        return $this->staticCache[$key];
    }
}

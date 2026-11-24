<?php

declare(strict_types=1);

namespace Componenta\DI;

use Componenta\DI\Exception\ExceptionInterface;
use Componenta\DI\Exception\InvalidCallableException;
use Componenta\DI\Internal\AvailableEntryLookup;
use Psr\Container\ContainerInterface;
use Throwable;

use function Componenta\DI\Internal\is_public_callable;
use function Componenta\DI\Internal\is_qualified_method_pair;
use function Componenta\DI\Internal\try_capture_qualified_callable;

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
                return is_public_callable($callable, deferAbstractDispatch: true) ? $callable : $this->resolveArray($callable);
            }

            if (is_public_callable($callable)) {
                return $callable;
            }

            throw InvalidCallableException::forValue($callable);
        } catch (ExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw InvalidCallableException::forValue($callable, $e);
        }
    }

    /**
     * The executor uses this path only for the exact built-in resolver.
     *
     * @param string|array<array-key,mixed> $callable
     * @return array{callable,array<array-key,mixed>|null}
     */
    // @phpstan-ignore method.unused (Invoked by the private-scope callable resolver.)
    private function resolveForInvocation(string|array $callable): array
    {
        $qualified = null;
        try {
            if (is_string($callable)) {
                $resolved = $this->selectString($callable, true, $qualified);
            } else {
                $callable = self::snapshotCallable($callable);
                $resolved = self::validateCandidate($callable, true, $qualified, deferAbstractDispatch: true)
                    ?? $this->selectArray($callable, true, $qualified);
            }

            return [$resolved, $qualified];
        } catch (ExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw InvalidCallableException::forValue($callable, $e);
        }
    }

    protected function resolveString(string $callable): callable
    {
        return $this->selectString($callable);
    }

    /**
     * @param array<array-key,mixed>|null $qualified
     * @return callable
     */
    private function selectString(string $callable, bool $capture = false, ?array &$qualified = null): mixed
    {
        if (AvailableEntryLookup::has($this->container, $callable)) {
            $entry = AvailableEntryLookup::get($this->container, $callable);
            if (is_array($entry)) {
                $entry = self::snapshotCallable($entry);
            }
            return self::validateCandidate($entry, $capture, $qualified)
                ?? throw InvalidCallableException::forNonInvokable($callable);
        }

        if (str_contains($callable, '::')) {
            if (is_public_callable($callable, deferAbstractDispatch: true)) {
                return $callable;
            }
            return $capture ? $this->selectClassMethod($callable, true, $qualified) : $this->resolveClassMethod($callable);
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
        return $this->selectClassMethod($callable);
    }

    /**
     * @param array<array-key,mixed>|null $qualified
     * @return callable
     */
    private function selectClassMethod(string $callable, bool $capture = false, ?array &$qualified = null): mixed
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
            if (!str_contains($method, '::') && is_public_callable($callable, deferAbstractDispatch: true)) {
                return $callable;
            }
        }
        if (AvailableEntryLookup::has($this->container, $class)) {
            $entry = AvailableEntryLookup::get($this->container, $class);
            if (is_object($entry)) {
                $resolved = self::validateCandidate([$entry, $method], $capture, $qualified);
                if ($resolved !== null) {
                    return $resolved;
                }
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
        return $this->selectArray($callable);
    }

    /**
     * @param array<array-key,mixed> $callable
     * @param array<array-key,mixed>|null $qualified
     * @return callable
     */
    private function selectArray(array $callable, bool $capture = false, ?array &$qualified = null): mixed
    {
        if (count($callable) !== 2 || !array_key_exists(0, $callable) || !array_key_exists(1, $callable)) {
            throw InvalidCallableException::forValue($callable);
        }

        [$objectOrClass, $method] = $callable;
        if (!is_string($method) || $method === '') {
            throw InvalidCallableException::forValue($callable);
        }

        if (is_object($objectOrClass)) {
            $resolved = [$objectOrClass, $method];
            // The invocation path already probed this exact qualified object pair.
            if ((!$capture || !str_contains($method, '::')) && is_public_callable($resolved)) {
                return $resolved;
            }
            throw InvalidCallableException::forMethod($objectOrClass::class, $method);
        }

        if (!is_string($objectOrClass) || $objectOrClass === '') {
            throw InvalidCallableException::forValue($callable);
        }

        $unqualifiedMethod = !str_contains($method, '::');
        $canDiscoverNative = $unqualifiedMethod && !class_exists($objectOrClass, false);
        if (AvailableEntryLookup::has($this->container, $objectOrClass)) {
            // Availability checks may load the native owner before instantiation.
            if ($canDiscoverNative && class_exists($objectOrClass, false) && is_public_callable($callable, deferAbstractDispatch: true)) {
                return $callable;
            }
            $entry = AvailableEntryLookup::get($this->container, $objectOrClass);
            if (is_object($entry)) {
                $resolved = self::validateCandidate([$entry, $method], $capture, $qualified);
                if ($resolved !== null) {
                    return $resolved;
                }
            }
            throw InvalidCallableException::forMethod($objectOrClass, $method);
        }

        if (!self::classOrInterfaceExists($objectOrClass)) {
            throw InvalidCallableException::forValue($callable);
        }
        if ($unqualifiedMethod && is_public_callable($callable, deferAbstractDispatch: true)) {
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

    /**
     * @param array<array-key,mixed>|null $qualified
     * @return callable|null
     */
    private static function validateCandidate(mixed $candidate, bool $capture, ?array &$qualified, bool $deferAbstractDispatch = false): mixed
    {
        if ($capture && is_array($candidate) && is_qualified_method_pair($candidate)) {
            $resolved = try_capture_qualified_callable($candidate, $deferAbstractDispatch);
            if ($resolved !== null) {
                // Preserve an object named by the original pair even when a
                // static capture does not bind that object as its receiver.
                $qualified = $candidate;
            }
            return $resolved;
        }

        return is_public_callable($candidate, $deferAbstractDispatch) ? $candidate : null;
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

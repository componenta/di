<?php

declare(strict_types=1);

namespace Componenta\DI;

use Closure;
use Componenta\DI\Exception\ExceptionInterface;
use Componenta\DI\Exception\InvalidCallableException;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\Reflection\Reflection;
use InvalidArgumentException;
use LogicException;
use ReflectionFunction;
use ReflectionParameter;
use Throwable;
use WeakMap;

/** DI-aware callable executor. */
final class CallableExecutor implements CallableExecutorInterface
{
    /** @var WeakMap<Closure, list<ParameterTarget>>|null */
    private ?WeakMap $closureTargets = null;

    /** @var array<string, list<ParameterTarget>|null> */
    private array $callableTargets = [];

    public function __construct(
        private readonly CallableResolverInterface $callableResolver,
        private readonly ParametersResolver $parameters,
    ) {}

    /** @param array<string|int, mixed> $params */
    public function call(mixed $callable, array $params = []): mixed
    {
        try {
            $resolved = $this->callableResolver->resolve($callable);
            $targets = $this->targets($resolved);
            $arguments = $targets === null
                ? $params
                : $this->parameters->resolveTargets($targets, $params);
        } catch (ExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw InvalidCallableException::forValue($callable, $e);
        }

        // Deliberately outside the normalization boundary: once control enters
        // the explicit user callable, its throwables belong to application code.
        return call_user_func_array($resolved, $arguments);
    }

    public function resolve(mixed $callable): callable
    {
        try {
            return $this->callableResolver->resolve($callable);
        } catch (ExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw InvalidCallableException::forValue($callable, $e);
        }
    }

    /**
     * @return list<ParameterTarget>|null
     *
     * null denotes a native callable whose concrete signature is unavailable
     * because PHP dispatches it through magic method handling. An empty list
     * denotes a reflected callable that genuinely takes no arguments.
     */
    private function targets(callable $callable): ?array
    {
        if ($callable instanceof Closure) {
            $cache = $this->closureTargets ??= new WeakMap();
            if (isset($cache[$callable])) {
                return $cache[$callable];
            }

            $reflection = new ReflectionFunction($callable);
            /** @var list<ReflectionParameter> $parameters */
            $parameters = array_values($reflection->getParameters());

            return $cache[$callable] = $this->parameters->targets($parameters);
        }

        $key = self::cacheKey($callable);
        if (array_key_exists($key, $this->callableTargets)) {
            return $this->callableTargets[$key];
        }

        try {
            $reflection = Reflection::callable($callable);
        } catch (InvalidArgumentException) {
            // CallableResolver has already established native callability. If
            // reflection cannot expose a concrete method, PHP is dispatching
            // through __call()/__callStatic(); preserve native argument binding.
            return $this->callableTargets[$key] = null;
        }

        /** @var list<ReflectionParameter> $parameters */
        $parameters = array_values($reflection->getParameters());

        return $this->callableTargets[$key] = $this->parameters->targets($parameters);
    }

    private static function cacheKey(callable $callable): string
    {
        if (is_string($callable)) {
            return 'function:' . strtolower($callable);
        }
        if (is_array($callable)) {
            [$owner, $method] = $callable;
            $class = is_object($owner) ? $owner::class : $owner;
            return 'method:' . $class . '::' . strtolower((string) $method);
        }
        if (!is_object($callable)) {
            throw new LogicException('Resolved callable must be a function, method or invokable object.');
        }
        return 'invoke:' . $callable::class;
    }
}

<?php

declare(strict_types=1);

namespace Componenta\DI;

use Closure;
use Componenta\DI\Exception\ExceptionInterface;
use Componenta\DI\Exception\InvalidCallableException;
use Componenta\DI\Internal\CallableReflector;
use Componenta\DI\Internal\ResolutionMetadata;
use Componenta\DI\Internal\Resolver\Parameter\ParameterExecution;
use Componenta\DI\Internal\Resolver\Parameter\ParameterExecutionAccess;
use Componenta\DI\Internal\Resolver\Parameter\PreparedParameterPlan;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use LogicException;
use ReflectionFunction;
use ReflectionParameter;
use Throwable;

use function Componenta\DI\Internal\is_magic_closure_trampoline;

/** DI-aware callable executor. */
final class CallableExecutor implements CallableExecutorInterface
{
    /** @var array<string, PreparedParameterPlan> */
    private array $callablePlans = [];

    private readonly ParameterExecution $parameters;

    public function __construct(
        private readonly CallableResolverInterface $callableResolver,
        ParametersResolver $parameters,
    ) {
        $this->parameters = ParameterExecutionAccess::for($parameters);
    }

    /** @param array<string|int, mixed> $params */
    public function call(mixed $callable, array $params = []): mixed
    {
        try {
            $resolved = $this->callableResolver->resolve(
                is_array($callable) ? self::snapshotCallable($callable) : $callable,
            );
            if (is_array($resolved)) {
                $resolved = self::snapshotCallable($resolved);
            }
            $arguments = $this->resolveArguments($resolved, $params);
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
     * @template T of array<array-key,mixed>
     * @param T $callable
     * @return T
     */
    private static function snapshotCallable(array $callable): array
    {
        // Argument resolution may mutate caller-owned reference slots. Keep
        // the callable used for reflection and invocation on the same route.
        $snapshot = [];
        foreach ($callable as $key => $value) {
            $snapshot[$key] = $value;
        }

        /** @var T $snapshot */
        return $snapshot;
    }

    /**
     * @param array<string|int,mixed> $params
     * @return array<string|int,mixed>
     */
    private function resolveArguments(callable $callable, array $params): array
    {
        if ($callable instanceof PreparedCallable) {
            return ResolutionMetadata::publicParameters($params);
        }
        if (is_array($callable) && $callable[0] instanceof Closure
            && strcasecmp((string) $callable[1], '__invoke') === 0
        ) {
            $callable = $callable[0];
        }

        if (is_array($callable) && str_contains((string) $callable[1], '::')) {
            // Native qualified methods have a concrete selected declaration even
            // though method_exists() cannot inspect this array representation.
            // Prepare its native capture per call without caching the receiver.
            $callable = Closure::fromCallable($callable);
        }

        if ($callable instanceof Closure) {
            // Prepared targets retain ReflectionParameter instances and those
            // reflectors retain their declaring Closure. Caching such a plan,
            // even behind a WeakMap key, therefore creates a value -> key strong
            // reference and keeps closure captures alive. Closures are prepared
            // per invocation; stable named/method callables remain cacheable.
            $reflection = new ReflectionFunction($callable);
            if (is_magic_closure_trampoline($reflection)) {
                return ResolutionMetadata::publicParameters($params);
            }
            $params = ResolutionMetadata::invocationSlots($params);
            /** @var list<ReflectionParameter> $parameters */
            $parameters = array_values($reflection->getParameters());
            $plan = $this->parameters->prepare($parameters, $callable);
            return $this->parameters->resolvePrepared($plan, $params);
        }

        $key = self::cacheKey($callable);
        $cached = $this->callablePlans[$key] ?? null;
        if ($cached !== null) {
            // Plan availability can itself invoke autoloaders.
            $params = ResolutionMetadata::invocationSlots($params);
            if ($this->parameters->isCurrentPlan($cached)) {
                return $this->parameters->resolvePrepared($cached, $params);
            }
            unset($this->callablePlans[$key]);
        }

        $reflection = CallableReflector::reflect($callable);
        if ($reflection === null) {
            // Magic-dispatched names can vary on every call and have no signature to cache.
            return ResolutionMetadata::publicParameters($params);
        }
        if ($cached === null) {
            $params = ResolutionMetadata::invocationSlots($params);
        }

        /** @var list<ReflectionParameter> $parameters */
        $parameters = array_values($reflection->getParameters());
        $plan = $this->parameters->prepare($parameters);

        if ($this->parameters->isSealed && !$plan->requiresFreshValidation) {
            $this->callablePlans[$key] = $plan;
        }

        return $this->parameters->resolvePrepared($plan, $params);
    }

    private static function cacheKey(callable $callable): string
    {
        if (is_string($callable)) {
            return 'function:' . strtolower($callable);
        }
        if (is_array($callable)) {
            [$owner, $method] = $callable;
            $class = strtolower(is_object($owner) ? $owner::class : $owner);
            // Class aliases can contain ::, so the separator alone is ambiguous.
            return 'method:' . strlen($class) . ':' . $class . '::' . strtolower((string) $method);
        }
        if (!is_object($callable)) {
            throw new LogicException('Resolved callable must be a function, method or invokable object.');
        }
        return 'invoke:' . $callable::class;
    }
}

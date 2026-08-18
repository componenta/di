<?php

declare(strict_types=1);

namespace Componenta\DI;

use Closure;
use Componenta\DI\Exception\InvalidCallableException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\Reflection\Reflection;
use LogicException;
use ReflectionFunction;
use ReflectionParameter;
use WeakMap;

/** DI-aware callable executor. */
final class CallableExecutor implements CallableExecutorInterface
{
    /** @var WeakMap<Closure, list<ParameterTarget>>|null */
    private ?WeakMap $closureTargets = null;

    /** @var array<string, list<ParameterTarget>> */
    private array $closureSignatures = [];

    /** @var array<string, list<ParameterTarget>> */
    private array $callableTargets = [];

    private readonly CallableInvoker $invoker;

    public function __construct(
        private readonly CallableResolverInterface $callableResolver,
        private readonly ParametersResolver $parameters,
    ) {
        $this->invoker = new CallableInvoker();
    }

    /**
     * v4-compatible convenience API over the provenance-aware v5 executor.
     * Caller values remain explicit, while the legacy PSR-7 request transport
     * key is promoted to trusted framework context.
     *
     * @param array<string|int, mixed> $params
     */
    public function call(mixed $callable, array $params = []): mixed
    {
        return $this->execute($callable, ResolutionContext::fromLegacyParameters($params));
    }

    /** @throws InvalidCallableException|ResolutionException */
    public function execute(
        mixed $callable,
        ResolutionContext $context = new ResolutionContext(),
    ): mixed {
        $resolved = $this->callableResolver->resolve($callable);
        $targets = $this->targets($resolved);
        $arguments = $targets === []
            ? array_values($context->explicit)
            : $this->parameters->resolveTargets($targets, $context);

        return $this->invoker->call($resolved, $arguments);
    }

    public function resolve(mixed $callable): callable
    {
        return $this->callableResolver->resolve($callable);
    }

    /** @return list<ParameterTarget> */
    private function targets(callable $callable): array
    {
        if ($callable instanceof Closure) {
            $cache = $this->closureTargets ??= new WeakMap();
            if (isset($cache[$callable])) {
                return $cache[$callable];
            }

            $reflection = new ReflectionFunction($callable);
            $signature = implode("\0", [
                $reflection->getClosureScopeClass()?->getName() ?? '',
                $reflection->getClosureCalledClass()?->getName() ?? '',
                (string) $reflection,
            ]);
            /** @var list<ReflectionParameter> $parameters */
            $parameters = array_values($reflection->getParameters());

            return $cache[$callable] = $this->closureSignatures[$signature]
                ??= $this->parameters->targets($parameters);
        }

        if (self::isDynamicMethodCallable($callable)) {
            return [];
        }

        $key = self::cacheKey($callable);
        /** @var list<ReflectionParameter> $parameters */
        $parameters = array_values(Reflection::callable($callable)->getParameters());

        return $this->callableTargets[$key] ??= $this->parameters->targets($parameters);
    }

    private static function isDynamicMethodCallable(callable $callable): bool
    {
        if (is_array($callable) && count($callable) === 2) {
            [$owner, $method] = $callable;
            $class = is_object($owner) ? $owner::class : $owner;
            return !method_exists($class, $method);
        }

        if (is_string($callable) && str_contains($callable, '::')) {
            [$class, $method] = explode('::', $callable, 2);
            return $class !== '' && $method !== '' && !method_exists($class, $method);
        }

        return false;
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

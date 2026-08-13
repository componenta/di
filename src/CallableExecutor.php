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
use WeakMap;

/**
 * Executes callables with dependency injection.
 *
 * Resolves callable representations and invokes them with auto-wired parameters.
 * Parameters can be provided explicitly or resolved from container.
 */
class CallableExecutor implements CallableExecutorInterface
{
    /** @var WeakMap<Closure, list<ParameterTarget>>|null */
    private ?WeakMap $closureTargets = null;

    /** @var array<string, list<ParameterTarget>> */
    private array $closureSignatures = [];

    /** @var array<string, list<ParameterTarget>> */
    private array $callableTargets = [];

    private readonly CallableInvoker $invoker;

    public function __construct(
        protected readonly CallableResolverInterface $callableResolver,
        protected readonly ParametersResolver $parametersResolver,
    ) {
        $this->invoker = new CallableInvoker();
    }

    /**
     * @throws InvalidCallableException If the callable cannot be resolved or invoked.
     * @throws ResolutionException      If a parameter cannot be resolved.
     */
    public function call(mixed $callable, array $params = []): mixed
    {
        $resolved = $this->callableResolver->resolve($callable);
        $targets = $this->targets($resolved);
        $arguments = $targets === []
            ? $params
            : $this->parametersResolver->resolveTargets($targets, $params);

        return $this->invoker->call($resolved, $arguments);
    }

    /** @return list<ParameterTarget> */
    private function targets(callable $callable): array
    {
        if ($callable instanceof Closure) {
            $closureTargets = $this->closureTargets ??= new WeakMap();

            if (isset($closureTargets[$callable])) {
                return $closureTargets[$callable];
            }

            $reflection = new ReflectionFunction($callable);
            $signature = self::closureSignature($reflection);

            return $closureTargets[$callable]
                = $this->closureSignatures[$signature]
                    ??= $this->parametersResolver->targets($reflection->getParameters());
        }

        if (self::isDynamicMethodCallable($callable)) {
            return [];
        }

        $key = self::cacheKey($callable);

        return $this->callableTargets[$key] ??= $this->parametersResolver->targets(
            Reflection::callable($callable)->getParameters(),
        );
    }

    private static function closureSignature(ReflectionFunction $reflection): string
    {
        return implode("\0", [
            $reflection->getClosureScopeClass()?->getName() ?? '',
            $reflection->getClosureCalledClass()?->getName() ?? '',
            (string) $reflection,
        ]);
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

            return $class !== ''
                && $method !== ''
                && !method_exists($class, $method);
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

    public function resolve(mixed $callable): callable
    {
        return $this->callableResolver->resolve($callable);
    }
}

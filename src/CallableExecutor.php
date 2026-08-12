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
 * Explicit invocation arguments are resolved independently from ambient
 * resolution context. This keeps caller mistakes observable while still
 * allowing request/framework state to participate in DI resolution.
 *
 * @example Basic usage
 * ```php
 * $executor->call(fn(LoggerInterface $logger) => $logger->info('Hello'));
 * ```
 *
 * @example With explicit arguments
 * ```php
 * $executor->call([UserService::class, 'create'], ['name' => 'John']);
 * ```
 *
 * @example With ambient context
 * ```php
 * $executor->call(
 *     $controller,
 *     arguments: ['id' => 42],
 *     context: [ServerRequestInterface::class => $request],
 * );
 * ```
 */
class CallableExecutor implements CallableExecutorInterface
{
    /** @var WeakMap<Closure, list<ParameterTarget>>|null */
    private ?WeakMap $closureTargets = null;

    /** @var array<string, list<ParameterTarget>> */
    private array $closureSignatures = [];

    /** @var array<string, list<ParameterTarget>> */
    private array $callableTargets = [];

    public function __construct(
        protected readonly CallableResolverInterface $callableResolver,
        protected readonly ParametersResolver $parametersResolver,
    ) {}

    /**
     * Exceptions thrown by the callable itself propagate unchanged.
     *
     * @param array<string|int, mixed> $arguments Explicit callable arguments.
     * @param array<string|int, mixed> $context Ambient values available to DI resolvers.
     *
     * @throws InvalidCallableException If the callable cannot be resolved.
     * @throws ResolutionException      If a parameter cannot be resolved or an explicit argument is unused.
     */
    public function call(
        mixed $callable,
        array $arguments = [],
        array $context = [],
    ): mixed {
        $resolved = $this->callableResolver->resolve($callable);
        $targets = $this->targets($resolved);

        if ($targets === []) {
            return $resolved(...$arguments);
        }

        return $resolved(...$this->parametersResolver->resolveTargets(
            $targets,
            $arguments,
            $context,
        ));
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

        // PHP considers methods handled through __call()/__callStatic() valid
        // callables even though no ReflectionMethod exists for the requested
        // dynamic name. There is no concrete signature for DI to inspect, so
        // invoke these callables with the explicit argument list as-is.
        if (self::isDynamicMethodCallable($callable)) {
            return [];
        }

        $key = self::cacheKey($callable);

        return $this->callableTargets[$key] ??= $this->parametersResolver->targets(
            Reflection::callable($callable)->getParameters(),
        );
    }

    /**
     * ReflectionFunction::__toString() does not include the closure's lexical
     * or called class. Those scopes affect `self`, `parent`, and `static`
     * parameter types, especially for closures declared in traits and reused
     * by multiple classes, so they are part of the reusable metadata key.
     */
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

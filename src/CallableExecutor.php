<?php

declare(strict_types=1);

namespace Componenta\DI;

use Componenta\DI\Exception\InvalidCallableException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\Reflection\Reflection;

/**
 * Executes callables with dependency injection.
 *
 * Resolves callable representations and invokes them with auto-wired parameters.
 * Parameters can be provided explicitly or resolved from container.
 *
 * @example Basic usage
 * ```php
 * $executor->call(fn(LoggerInterface $logger) => $logger->info('Hello'));
 * ```
 *
 * @example With explicit parameters
 * ```php
 * $executor->call([UserService::class, 'create'], ['name' => 'John']);
 * ```
 */
class CallableExecutor implements CallableExecutorInterface
{
    public function __construct(
        protected readonly CallableResolverInterface $callableResolver,
        protected readonly ParametersResolver $parametersResolver,
    ) {}

    /**
     * Exceptions thrown by the callable itself propagate unchanged.
     *
     * @throws InvalidCallableException  If the callable cannot be resolved.
     * @throws ResolutionException     If a parameter cannot be resolved.
     */
    public function call(mixed $callable, array $params = []): mixed
    {
        $resolved   = $this->callableResolver->resolve($callable);
        $parameters = Reflection::callable($resolved)->getParameters();

        if ($parameters === []) {
            return $resolved();
        }

        $resolvedParams = $this->parametersResolver->resolve($parameters, $params);

        return $resolved(...$resolvedParams);
    }

    public function resolve(mixed $callable): callable
    {
        return $this->callableResolver->resolve($callable);
    }
}

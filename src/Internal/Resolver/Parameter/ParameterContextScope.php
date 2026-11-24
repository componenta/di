<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Parameter;

use Closure;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use ReflectionParameter;
use Throwable;

/** Reserves the invocation's writer for the resolver that creates it. @internal */
abstract class ParameterContextScope
{
    /**
     * @param array<string|int,mixed> $provided
     * @param array<int,mixed> $resolved
     * @return array{ParameterResolutionContext, Closure(int,mixed):void, ParameterResolutionContext}
     */
    abstract protected static function startInvocation(array $provided, array $resolved): array;

    /** @return array<string|int,mixed> */
    abstract protected function invocationArguments(): array;

    abstract protected function parameterFailure(ReflectionParameter $parameter, ?string $reason, ?Throwable $previous): ResolutionException;
}

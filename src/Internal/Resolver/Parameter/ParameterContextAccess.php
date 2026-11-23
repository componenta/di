<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Parameter;

use Closure;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Internal\Resolver\Parameter\Request\MappedRequestPublication;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use ReflectionParameter;
use Throwable;

/** Starts a resolution and keeps the literal context with the argument owner. @internal */
abstract class ParameterContextAccess extends ParameterContextScope
{
    /**
     * @param array<string|int,mixed> $provided
     * @param array<int,mixed> $resolved
     * @return array{ParameterResolutionContext, Closure(int,mixed):void, ParameterResolutionContext}
     */
    public static function begin(array $provided, array $resolved): array
    {
        return ParameterResolutionContext::startInvocation($provided, $resolved);
    }

    /** @return array<string|int,mixed> */
    public static function provided(ParameterResolutionContext $context): array
    {
        return $context->invocationArguments();
    }

    public static function failure(
        ParameterResolutionContext $context,
        ReflectionParameter $parameter,
        ?string $reason = null,
        ?Throwable $previous = null,
    ): ResolutionException {
        return $context->parameterFailure($parameter, $reason, $previous);
    }

    public static function captureMappedResults(ParameterResolutionContext $context): int
    {
        return $context->beginMappedResults();
    }

    public static function releaseMappedResults(ParameterResolutionContext $context, int $execution): ?MappedRequestPublication
    {
        return $context->endMappedResults($execution);
    }

    /** @param list<Closure():void> $checks */
    public static function recordMappedResult(ParameterResolutionContext $context, object $result, array $checks): void
    {
        $context->keepMappedResultChecks($result, $checks);
    }
}

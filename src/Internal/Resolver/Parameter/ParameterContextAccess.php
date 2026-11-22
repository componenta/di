<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Parameter;

use Closure;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;

/** Starts a resolution and supplies literal values to the invocation pipeline. @internal */
abstract class ParameterContextAccess extends ParameterContextScope
{
    /**
     * @param array<string|int,mixed> $provided
     * @param array<int,mixed> $resolved
     * @return array{ParameterResolutionContext, Closure(int,mixed):void}
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
}

<?php

declare(strict_types=1);

namespace Componenta\DI\Compile;

use ReflectionParameter;

interface ParameterPlanResolverInterface
{
    /**
     * @param array<string|int, mixed> $providedParameters
     * @param array<int, mixed> $resolvedParameters
     * @return array{0: int, 1: mixed}|null
     */
    public function resolveParameterPlan(
        ReflectionParameter $parameter,
        mixed $payload,
        array $providedParameters = [],
        array $resolvedParameters = [],
    ): ?array;
}

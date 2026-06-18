<?php

declare(strict_types=1);

namespace Componenta\DI\Compile;

use ReflectionProperty;

interface PropertyPlanResolverInterface
{
    /**
     * @param array<string, mixed> $context
     * @return array{0: ReflectionProperty, 1: mixed}|null
     */
    public function resolvePropertyPlan(
        ReflectionProperty $property,
        mixed $payload,
        array $context = [],
    ): ?array;
}

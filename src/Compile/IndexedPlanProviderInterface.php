<?php

declare(strict_types=1);

namespace Componenta\DI\Compile;

/**
 * Supplies targeted compiled DI plans without materializing the full map.
 */
interface IndexedPlanProviderInterface extends PlanProviderInterface
{
    /**
     * @return array<int, string|array{kind: string, payload: mixed}>|null
     */
    public function parameterPlan(string $class, string $method): ?array;

    /**
     * @return string|array{kind: string, payload: mixed}|null
     */
    public function propertyPlan(string $class, string $property): string|array|null;
}

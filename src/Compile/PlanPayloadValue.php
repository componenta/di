<?php

declare(strict_types=1);

namespace Componenta\DI\Compile;

/**
 * Guards values embedded into compiled DI plans.
 */
final class PlanPayloadValue
{
    public static function isCacheable(mixed $value): bool
    {
        if ($value === null || is_scalar($value) || $value instanceof \UnitEnum) {
            return true;
        }

        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (!self::isCacheable($item)) {
                return false;
            }
        }

        return true;
    }
}

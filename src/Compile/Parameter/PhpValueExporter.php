<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Parameter;

use UnitEnum;

/** Exports values that can be embedded safely in generated PHP source. */
final class PhpValueExporter
{
    public static function export(mixed $value): ?string
    {
        if ($value === null || is_scalar($value)) {
            return var_export($value, true);
        }

        if ($value instanceof UnitEnum) {
            return sprintf('\\%s::%s', $value::class, $value->name);
        }

        if (!is_array($value)) {
            return null;
        }

        $items = [];

        foreach ($value as $key => $item) {
            $exportedKey = self::export($key);
            $exportedItem = self::export($item);

            if ($exportedKey === null || $exportedItem === null) {
                return null;
            }

            $items[] = sprintf('%s => %s', $exportedKey, $exportedItem);
        }

        return '[' . implode(', ', $items) . ']';
    }
}

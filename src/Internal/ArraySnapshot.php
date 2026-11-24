<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use ReflectionReference;

/** Copies array graphs without retaining references owned by the caller. @internal */
trait ArraySnapshot
{
    /**
     * Owns the array graph while retaining application object identity and array order.
     * Reports whether the detached graph still contains references between arrays.
     * @template T of array<array-key,mixed>
     * @param T $parameters
     * @param-out bool $containsArrayReferences
     * @return T
     */
    private static function snapshot(array $parameters, ?bool &$containsArrayReferences = null): array
    {
        $arrays = [];
        /** @var T|null $snapshot Reference cells are replaced without changing the input shape. */
        $snapshot = self::detachReferences($parameters, $arrays);
        $containsArrayReferences = $arrays !== [];
        return $snapshot ?? $parameters;
    }

    /**
     * @template TKey of array-key
     * @template TValue
     * @param array<TKey,TValue> $values
     * @param array<string,array<array-key,mixed>> $arrays
     * @return array<TKey,TValue>|null Null indicates a reference-free subtree.
     */
    private static function detachReferences(array $values, array &$arrays): ?array
    {
        $changes = [];
        foreach ($values as $key => $value) {
            $reference = ReflectionReference::fromArrayElement($values, $key);
            if (!is_array($value)) {
                if ($reference !== null) {
                    $changes[$key] = $value;
                }
                continue;
            }
            if ($reference === null) {
                $snapshot = self::detachReferences($value, $arrays);
                if ($snapshot !== null) {
                    $changes[$key] = $snapshot;
                }
                continue;
            }

            $id = $reference->getId();
            if (!isset($arrays[$id])) {
                // Install the destination cell first so back edges stay inside the copy.
                $arrays[$id] = [];
                $arrays[$id] = self::detachReferences($value, $arrays) ?? $value;
            }
            $changes[$key] = &$arrays[$id];
        }

        /** @var array<TKey,TValue>|null $snapshot The array shape is unchanged. */
        $snapshot = $changes === [] ? null : array_replace($values, $changes);
        return $snapshot;
    }
}

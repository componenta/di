<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use ReflectionReference;

/**
 * Filters reserved resolution parameter names before arguments reach extensions.
 *
 * @internal
 */
final class ResolutionMetadata
{
    private const string PREFIX = "\0componenta.di.";

    /**
     * Removes all DI-owned metadata keys before parameters cross a public extension boundary.
     *
     * @param array<string|int, mixed> $parameters
     * @return array<string|int, mixed>
     */
    public static function publicParameters(array $parameters): array
    {
        foreach ($parameters as $key => $_value) {
            if (is_string($key) && str_starts_with($key, self::PREFIX)) {
                unset($parameters[$key]);
            }
        }

        return $parameters;
    }

    /**
     * Selects invocation slots while retaining literal argument values.
     * @template TKey of array-key
     * @template TValue
     * @param array<TKey,TValue> $parameters
     * @return array<TKey,TValue>
     */
    public static function invocationSlots(array $parameters): array
    {
        $referenced = [];
        foreach ($parameters as $key => $value) {
            if (ReflectionReference::fromArrayElement($parameters, $key) !== null) {
                $referenced[$key] = $value;
            }
        }
        return $referenced === [] ? $parameters : array_replace($parameters, $referenced);
    }

    /**
     * Owns the array graph while retaining application object identity and array order.
     * @template T of array<array-key,mixed>
     * @param T $parameters
     * @return T
     */
    public static function snapshot(array $parameters): array
    {
        $arrays = [];
        /** @var T|null $snapshot Reference cells are replaced without changing the input shape. */
        $snapshot = self::detachReferences($parameters, $arrays);
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

    private function __construct() {}
}

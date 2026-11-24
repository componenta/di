<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use ReflectionReference;

/** Internal metadata transport helpers for DI resolution parameter arrays. @internal */
final class ResolutionMetadata
{
    use ArraySnapshot {
        snapshot as public;
    }

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
     * Applies public overrides without allowing them to replace DI-owned metadata.
     *
     * @param array<string|int, mixed> $parameters
     * @param array<string|int, mixed> $overrides
     * @return array<string|int, mixed>
     */
    public static function mergePublicPreservingInternal(array $parameters, array $overrides): array
    {
        $internal = [];
        foreach ($parameters as $key => $value) {
            if (is_string($key) && str_starts_with($key, self::PREFIX)) {
                $internal[$key] = $value;
            }
        }

        return array_replace(
            self::publicParameters($parameters),
            self::publicParameters($overrides),
            $internal,
        );
    }

    /**
     * Selects argument values without retaining caller-owned reference slots.
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

    private function __construct() {}
}

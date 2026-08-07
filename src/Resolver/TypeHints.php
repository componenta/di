<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver;

use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

/** Canonical contextual type inspection for parameter and property injection. */
final class TypeHints
{
    /**
     * Returns one concrete class/interface name for a named non-builtin type.
     *
     * `self` and `parent` are meaningful only together with the declaring
     * class. Without that context they deliberately resolve to null instead of
     * leaking the pseudo-name into a container lookup.
     *
     * @return class-string|null
     */
    public static function classOf(
        ?ReflectionType $type,
        ?ReflectionClass $declaringClass = null,
    ): ?string {
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        return self::resolveClassName($type->getName(), $declaringClass);
    }

    /**
     * Every concrete class/interface name mentioned by a named, union,
     * intersection or DNF type, preserving declaration order.
     *
     * @return list<class-string>
     */
    public static function classNames(
        ?ReflectionType $type,
        ?ReflectionClass $declaringClass = null,
    ): array {
        if ($type instanceof ReflectionNamedType) {
            $class = self::classOf($type, $declaringClass);

            return $class === null ? [] : [$class];
        }

        if (!$type instanceof ReflectionUnionType
            && !$type instanceof ReflectionIntersectionType
        ) {
            return [];
        }

        $classes = [];

        foreach ($type->getTypes() as $nested) {
            foreach (self::classNames($nested, $declaringClass) as $class) {
                $classes[$class] = true;
            }
        }

        return array_keys($classes);
    }

    /** Matches a value against the declared type in its lexical class scope. */
    public static function matches(
        ?ReflectionType $type,
        mixed $value,
        ?ReflectionClass $declaringClass = null,
    ): bool {
        if ($type === null) {
            return true;
        }

        if ($value === null && $type->allowsNull()) {
            return true;
        }

        if ($type instanceof ReflectionNamedType) {
            if (!$type->isBuiltin()) {
                $class = self::resolveClassName($type->getName(), $declaringClass);

                return $class !== null
                    && is_object($value)
                    && $value instanceof $class;
            }

            return match ($type->getName()) {
                'mixed' => true,
                'null' => $value === null,
                'true' => $value === true,
                'false' => $value === false,
                'bool' => is_bool($value),
                'int' => is_int($value),
                'float' => is_float($value),
                'string' => is_string($value),
                'array' => is_array($value),
                'object' => is_object($value),
                'callable' => is_callable($value),
                'iterable' => is_iterable($value),
                default => false,
            };
        }

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $nested) {
                if (self::matches($nested, $value, $declaringClass)) {
                    return true;
                }
            }

            return false;
        }

        if ($type instanceof ReflectionIntersectionType) {
            foreach ($type->getTypes() as $nested) {
                if (!self::matches($nested, $value, $declaringClass)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /** @return class-string|null */
    private static function resolveClassName(
        string $name,
        ?ReflectionClass $declaringClass,
    ): ?string {
        return match ($name) {
            'self', 'static' => $declaringClass?->getName(),
            'parent' => ($parent = $declaringClass?->getParentClass()) === false
                ? null
                : $parent?->getName(),
            default => $name,
        };
    }

    private function __construct() {}
}

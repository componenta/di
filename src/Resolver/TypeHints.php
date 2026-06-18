<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver;

use ReflectionNamedType;
use ReflectionType;

/**
 * Tiny helper for the recurring "give me the class/interface name behind this
 * type hint, or null" pattern.
 *
 * Lives close to the resolvers because every consumer is one of them; pulling
 * it into the broader {@see \Componenta\Reflection} package would broaden that
 * library's surface for a one-line predicate.
 *
 * @internal
 */
final class TypeHints
{
    /**
     * Returns the class/interface name when `$type` is a non-builtin named
     * type, `null` otherwise.
     *
     * Union and intersection types are not handled here - autowiring against
     * them is intentionally undefined; callers that want richer behaviour
     * should walk the type themselves via {@see \Componenta\Reflection\ReflectionType}.
     */
    public static function classOf(?ReflectionType $type): ?string
    {
        return $type instanceof ReflectionNamedType && !$type->isBuiltin()
            ? $type->getName()
            : null;
    }
}

<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Property;

use ReflectionProperty;

/**
 * Resolves property values from context array by property name.
 *
 * Runtime-only resolver - cannot be compiled as it depends on
 * runtime context data.
 *
 * @example
 * ```php
 * class User {
 *     public string $name;
 *     public int $age;
 * }
 * 
 * $resolver->resolveAll($user, ['name' => 'John', 'age' => 30]);
 * ```
 */
final class ArrayResolver implements
    PropertyResolverInterface
{
    public function resolveProperty(ReflectionProperty $property, array $context = []): ?array
    {
        $name = $property->getName();

        if (array_key_exists($name, $context)) {
            return [$property, $context[$name]];
        }

        return null;
    }
}

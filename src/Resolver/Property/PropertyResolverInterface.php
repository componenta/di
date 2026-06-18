<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Property;

use Componenta\DI\Exception\ResolutionException;
use ReflectionProperty;

/**
 * Resolves values for class properties.
 *
 * The method is named `resolveProperty` (rather than a plain `resolve`) so
 * that a single class can simultaneously implement this interface and
 * {@see \Componenta\DI\Resolver\Parameter\ParameterResolverInterface::resolveParameter()},
 * which is how merged resolvers cover both parameters and properties without
 * duplication.
 */
interface PropertyResolverInterface
{
    /**
     * Attempts to resolve a property value.
     *
     * @param ReflectionProperty   $property The property to resolve.
     * @param array<string, mixed> $context  Contextual data for resolution.
     *
     * @return array{0: ReflectionProperty, 1: mixed}|null Returns [property, value] or null if cannot resolve.
     *
     * @throws ResolutionException If resolution fails hard.
     */
    public function resolveProperty(
        ReflectionProperty $property,
        array $context = [],
    ): ?array;
}

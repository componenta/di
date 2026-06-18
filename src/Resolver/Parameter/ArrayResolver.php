<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

use Componenta\DI\Exception\ResolutionException;
use Componenta\Reflection\ReflectionType;
use ReflectionParameter;

/**
 * Resolves parameters from provided array by name or position.
 *
 * First resolver in the default chain. Looks up values using:
 * 1. Parameter name as array key
 * 2. Parameter position as array index
 *
 * When a value is provided under the parameter's name or position but its
 * type does not satisfy the declared parameter type, an
 * {@see ResolutionException} is raised: silently falling through to
 * autowire/default resolvers would discard the caller's explicit value.
 *
 * Runtime-only resolver - cannot be compiled as it depends on
 * runtime-provided parameters.
 *
 * @example By name
 * ```php
 * function greet(string $name) {}
 * $resolver->resolve($param, ['name' => 'John']); // 'John'
 * ```
 *
 * @example By position
 * ```php
 * function greet(string $name) {}
 * $resolver->resolve($param, ['John']); // 'John' (position 0)
 * ```
 */
final class ArrayResolver implements
    ParameterResolverInterface
{
    public function resolveParameter(
        ReflectionParameter $parameter,
        array $providedParameters = [],
        array $resolvedParameters = [],
    ): ?array {
        $name = $parameter->getName();
        $position = $parameter->getPosition();
        $type = $parameter->getType();

        if (array_key_exists($name, $providedParameters)) {
            $value = $providedParameters[$name];

            if (ReflectionType::match($type, $value)) {
                return [$position, $value];
            }

            throw ResolutionException::forParameter(
                $parameter,
                reason: sprintf(
                    'value provided for "$%s" does not satisfy declared type',
                    $name,
                ),
                providedParameters: $providedParameters,
                resolvedParameters: $resolvedParameters,
            );
        }

        if (array_key_exists($position, $providedParameters)) {
            $value = $providedParameters[$position];

            if (ReflectionType::match($type, $value)) {
                return [$position, $value];
            }

            throw ResolutionException::forParameter(
                $parameter,
                reason: sprintf(
                    'value provided at position %d does not satisfy declared type',
                    $position,
                ),
                providedParameters: $providedParameters,
                resolvedParameters: $resolvedParameters,
            );
        }

        return null;
    }
}

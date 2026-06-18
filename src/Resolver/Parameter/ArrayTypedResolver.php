<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;

/**
 * Resolves parameters by matching type against provided values.
 *
 * Useful when passing objects without specifying parameter names.
 * Checks:
 * 1. Array key matching type name
 * 2. Values that are instanceof the parameter type
 *
 * Skips built-in types (int, string, array, etc.).
 * Runtime-only resolver - cannot be compiled.
 *
 * @example By instanceof
 * ```php
 * function handle(ServerRequestInterface $request, LoggerInterface $logger) {}
 * 
 * // Pass objects without keys - matched by type
 * $resolver->resolveAll($method, [$serverRequest, $fileLogger]);
 * ```
 *
 * @example By type key
 * ```php
 * $resolver->resolveAll($method, [
 *     ServerRequestInterface::class => $request,
 * ]);
 * ```
 */
final class ArrayTypedResolver implements
    ParameterResolverInterface
{
    public function resolveParameter(
        ReflectionParameter $parameter,
        array $providedParameters = [],
        array $resolvedParameters = [],
    ): ?array {
        $type = $parameter->getType();

        if ($type === null) {
            return null;
        }

        $types = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];

        foreach ($types as $t) {
            if (!$t instanceof ReflectionNamedType || $t->isBuiltin()) {
                continue;
            }

            $typeName = $t->getName();

            // Check by type name key
            if (array_key_exists($typeName, $providedParameters)) {
                return [$parameter->getPosition(), $providedParameters[$typeName]];
            }

            // Check by instanceof
            foreach ($providedParameters as $value) {
                if ($value instanceof $typeName) {
                    return [$parameter->getPosition(), $value];
                }
            }
        }

        return null;
    }
}

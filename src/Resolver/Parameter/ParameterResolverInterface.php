<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

use Componenta\DI\Exception\ResolutionException;
use ReflectionParameter;

/**
 * Resolves values for function/method parameters.
 *
 * The method is named `resolveParameter` (rather than a plain `resolve`) so
 * that a single class can simultaneously implement this interface and
 * {@see \Componenta\DI\Resolver\Property\PropertyResolverInterface::resolveProperty()},
 * which is how merged resolvers cover both parameters and properties without
 * duplication.
 */
interface ParameterResolverInterface
{
    /**
     * Attempts to resolve a parameter value.
     *
     * @param ReflectionParameter      $parameter          The parameter to resolve.
     * @param array<string|int, mixed> $providedParameters User-provided parameters.
     * @param array<int, mixed>        $resolvedParameters Already resolved parameters.
     *
     * @return array{0: int, 1: mixed}|null Returns [position, value] or null if cannot resolve.
     *
     * @throws ResolutionException If resolution fails hard.
     */
    public function resolveParameter(
        ReflectionParameter $parameter,
        array $providedParameters = [],
        array $resolvedParameters = [],
    ): ?array;
}

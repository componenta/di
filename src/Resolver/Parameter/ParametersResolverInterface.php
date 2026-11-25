<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

use Componenta\DI\Exception\ExceptionInterface;
use ReflectionParameter;

/** Resolves a complete invocation argument list without invoking the target. */
interface ParametersResolverInterface
{
    /**
     * The result is a native argument vector; optional native arguments can be
     * omitted, and variadic values are expanded into positional and named slots.
     *
     * @param list<ReflectionParameter> $parameters
     * @param array<string|int,mixed> $providedParameters
     * @return array<array-key,mixed>
     * @throws ExceptionInterface If resolution fails.
     */
    public function resolve(array $parameters, array $providedParameters = []): array;
}

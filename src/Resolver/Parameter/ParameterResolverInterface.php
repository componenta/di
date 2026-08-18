<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Target\ParameterTarget;

/**
 * Resolves one constructor/callable parameter.
 *
 * This is the only extension contract allowed to produce parameter values.
 * Attribute composition may classify and validate a parameter, but the value
 * itself must still be returned by a ParameterResolverInterface.
 */
interface ParameterResolverInterface
{
    /**
     * Immutable target classification. Implementations must not mutate the
     * resolver chain or retain per-resolution state from this method.
     */
    public function supports(ParameterTarget $target): bool;

    /**
     * @return array{0: int, 1: mixed}|null Null continues the resolver chain.
     * @throws ResolutionException
     */
    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array;
}

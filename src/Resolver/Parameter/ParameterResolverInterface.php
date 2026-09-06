<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Target\ParameterTarget;

/**
 * Resolves one constructor/callable parameter.
 *
 * Implementations should throw {@see ResolutionException} for expected
 * resolver failures. Any foreign throwable is normalized by
 * {@see ParametersResolver} before it can leave the DI parameter pipeline.
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

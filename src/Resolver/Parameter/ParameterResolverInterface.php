<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Target\ParameterTarget;

/** Resolves one callable parameter from an immutable target and call context. */
interface ParameterResolverInterface
{
    /**
     * Whether the resolver can potentially handle this target.
     *
     * This is immutable metadata classification: implementations must be pure
     * and stable for the lifetime of the resolver. Returning true does not
     * guarantee a value in a particular call; resolveParameter() may still
     * return null based on the call context.
     */
    public function supports(ParameterTarget $target): bool;

    /**
     * @return array{0: int, 1: mixed}|null
     * @throws ResolutionException
     */
    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array;
}

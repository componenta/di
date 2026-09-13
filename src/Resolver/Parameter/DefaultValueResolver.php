<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

use Componenta\DI\Resolver\Target\ParameterTarget;

/** Resolves a native PHP parameter default or an empty variadic collection. */
final class DefaultValueResolver implements ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool
    {
        return $target->variadic || $target->hasDefault;
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        if ($target->variadic) {
            return [$target->position, []];
        }

        return $target->hasDefault
            ? [$target->position, $target->reflection->getDefaultValue()]
            : null;
    }
}

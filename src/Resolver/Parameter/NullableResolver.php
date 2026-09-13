<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

use Componenta\DI\Resolver\Target\ParameterTarget;

/** Final built-in resolver for nullable parameters. */
final class NullableResolver implements ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool
    {
        return !$target->variadic && $target->allowsNull;
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        return $this->supports($target)
            ? [$target->position, null]
            : null;
    }
}

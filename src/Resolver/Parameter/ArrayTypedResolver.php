<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

use Componenta\DI\Resolver\Target\ParameterTarget;

/** Resolves an explicitly provided value by declared class/interface key. */
final class ArrayTypedResolver implements ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool
    {
        return $target->typeNames !== [];
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        foreach ($target->typeNames as $typeName) {
            if (!array_key_exists($typeName, $context->provided)) {
                continue;
            }

            return [$target->position, $context->provided[$typeName]];
        }

        return null;
    }
}

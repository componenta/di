<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

use Componenta\DI\Attribute\Cast;
use Componenta\DI\Attribute\CurrentUser;
use Componenta\DI\Resolver\Target\ParameterTarget;

/** Resolves an explicit object registered under its declared class/interface type. */
final class ArrayTypedResolver implements ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool
    {
        return $target->typeNames !== []
            && !$target->hasAttribute(Cast::class)
            && !$target->hasAttribute(CurrentUser::class);
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        foreach ($target->typeNames as $typeName) {
            if (!array_key_exists($typeName, $context->provided)) {
                continue;
            }

            $value = $context->provided[$typeName];

            if (is_object($value) && $target->accepts($value)) {
                return [$target->position, $value];
            }
        }

        return null;
    }
}

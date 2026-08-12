<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

use Componenta\DI\Resolver\Target\ParameterTarget;

/** Resolves an explicit or contextual object registered under its declared type. */
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
            if (array_key_exists($typeName, $context->arguments)) {
                $value = $context->arguments[$typeName];

                if (is_object($value) && $target->accepts($value)) {
                    $context->consumeArgument($typeName);

                    return [$target->position, $value];
                }
            }

            if (!array_key_exists($typeName, $context->context)) {
                continue;
            }

            $value = $context->context[$typeName];

            if (is_object($value) && $target->accepts($value)) {
                return [$target->position, $value];
            }
        }

        return null;
    }
}

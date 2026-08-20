<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Target\ParameterTarget;

/** Resolves an explicit value by parameter name or position. */
final class ArrayResolver implements ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool
    {
        return true;
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        if (isset($context->provided[$target->name]) || array_key_exists($target->name, $context->provided)) {
            $value = $context->provided[$target->name];

            if ($target->accepts($value)) {
                return [$target->position, $value];
            }

            throw ResolutionException::forParameter(
                $target->reflection,
                reason: sprintf('value provided for "$%s" does not satisfy declared type', $target->name),
                providedParameters: $context->provided,
                resolvedParameters: $context->resolved,
            );
        }

        if (isset($context->provided[$target->position]) || array_key_exists($target->position, $context->provided)) {
            $value = $context->provided[$target->position];

            if ($target->accepts($value)) {
                return [$target->position, $value];
            }

            throw ResolutionException::forParameter(
                $target->reflection,
                reason: sprintf('value provided at position %d does not satisfy declared type', $target->position),
                providedParameters: $context->provided,
                resolvedParameters: $context->resolved,
            );
        }

        return null;
    }
}

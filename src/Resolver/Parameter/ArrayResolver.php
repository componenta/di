<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Internal\Resolver\Parameter\ParameterContextAccess;
use Componenta\DI\Internal\Resolver\Parameter\VariadicArguments;
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
        if ($target->variadic) {
            return VariadicArguments::provided($target, $context);
        }

        $provided = ParameterContextAccess::provided($context);
        if (isset($provided[$target->name]) || array_key_exists($target->name, $provided)) {
            $value = $provided[$target->name];

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

        if (isset($provided[$target->position]) || array_key_exists($target->position, $provided)) {
            $value = $provided[$target->position];

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

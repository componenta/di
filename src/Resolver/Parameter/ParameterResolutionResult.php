<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Target\ParameterTarget;

/** Validates a resolver result at the single parameter-pipeline boundary. */
final class ParameterResolutionResult
{
    /**
     * @param array<mixed> $result
     * @return array{0: int, 1: mixed}
     */
    public static function validate(
        array $result,
        ParameterResolverInterface $resolver,
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): array {
        if (array_keys($result) !== [0, 1]
            || !is_int($result[0])
            || $result[0] !== $target->position
        ) {
            throw ResolutionException::forParameter(
                $target->reflection,
                reason: sprintf(
                    'resolver "%s" returned an invalid result; expected [position %d, value]',
                    $resolver::class,
                    $target->position,
                ),
                providedParameters: $context->provided,
                resolvedParameters: $context->resolved,
            );
        }

        if (!$target->accepts($result[1])) {
            throw ResolutionException::forParameter(
                $target->reflection,
                reason: sprintf(
                    'resolver "%s" returned a value that does not satisfy the declared type',
                    $resolver::class,
                ),
                providedParameters: $context->provided,
                resolvedParameters: $context->resolved,
            );
        }

        /** @var array{0: int, 1: mixed} $result */
        return $result;
    }

    private function __construct() {}
}

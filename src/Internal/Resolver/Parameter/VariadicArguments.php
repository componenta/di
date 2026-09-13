<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Parameter;

use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Target\ParameterTarget;

/** Explicit input and collection validation for one variadic parameter. @internal */
final class VariadicArguments
{
    /** @return array{0:int,1:mixed}|null */
    public static function provided(ParameterTarget $target, ParameterResolutionContext $context): ?array
    {
        if (array_key_exists($target->name, $context->provided)) {
            return [$target->position, $context->provided[$target->name]];
        }

        $values = [];
        foreach ($context->provided as $position => $value) {
            if (is_int($position) && $position >= $target->position) {
                $values[$position] = $value;
            }
        }
        ksort($values, SORT_NUMERIC);
        return $values === [] ? null : [$target->position, array_values($values)];
    }

    /** @return array<array-key,mixed> */
    public static function validate(ParameterTarget $target, mixed $value, ParameterResolutionContext $context): array
    {
        if (!is_array($value)) {
            throw self::failure($target, $context, 'variadic value must be an array of arguments');
        }

        $fixedNames = [];
        foreach ($target->reflection->getDeclaringFunction()->getParameters() as $parameter) {
            if (!$parameter->isVariadic()) {
                $fixedNames[$parameter->getName()] = true;
            }
        }

        $named = false;
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $named = true;
                if (isset($fixedNames[$key])) {
                    throw self::failure($target, $context, sprintf(
                        'variadic argument "%s" would overwrite a preceding parameter',
                        $key,
                    ));
                }
            } elseif ($named) {
                throw self::failure($target, $context, 'positional variadic arguments must precede named arguments');
            }

            if (!$target->accepts($item)) {
                throw self::failure($target, $context, sprintf(
                    'variadic argument "%s" does not satisfy the declared element type',
                    (string) $key,
                ));
            }
        }
        return $value;
    }

    private static function failure(
        ParameterTarget $target,
        ParameterResolutionContext $context,
        string $reason,
    ): ResolutionException {
        return ResolutionException::forParameter(
            $target->reflection,
            reason: $reason,
            providedParameters: $context->provided,
            resolvedParameters: $context->resolved,
        );
    }

    private function __construct() {}
}

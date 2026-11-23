<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Componenta\DI\Exception\ParameterDiagnostic;
use Componenta\DI\Exception\ResolutionException;
use ReflectionParameter;
use Throwable;

/** Builds detached diagnostic records for resolution failures. @internal */
final class ResolutionDiagnostics
{
    /**
     * @template TKey of array-key
     * @param array<TKey,mixed> $arguments
     * @return array<TKey,string>
     */
    public static function argumentTypes(array $arguments): array
    {
        $types = [];
        foreach ($arguments as $key => $value) {
            $types[$key] = get_debug_type($value);
        }
        return $types;
    }

    /**
     * @param array<string|int,string> $providedTypes
     * @param array<int,string> $resolvedTypes
     */
    public static function parameter(
        ReflectionParameter $parameter,
        ?string $reason,
        array $providedTypes,
        array $resolvedTypes,
        ?Throwable $previous,
    ): ResolutionException {
        $context = self::formatFunctionName($parameter);
        $type = $parameter->getType();
        $diagnostic = new ParameterDiagnostic(
            $parameter->getName(),
            $parameter->getPosition(),
            $type === null ? null : (string) $type,
            $context,
        );

        return new ResolutionException(
            sprintf(
                'Cannot resolve parameter "$%s" of %s%s',
                $diagnostic->name,
                $context,
                self::suffix($reason, $previous),
            ),
            parameter: $diagnostic,
            parameterName: $diagnostic->name,
            parameterPosition: $diagnostic->position,
            parameterType: $diagnostic->type,
            parameterContext: $diagnostic->context,
            providedParameterTypes: $providedTypes,
            resolvedParameterTypes: $resolvedTypes,
            previous: $previous,
        );
    }

    public static function suffix(?string $reason, ?Throwable $previous): string
    {
        if ($reason !== null && $previous !== null) {
            return sprintf(': %s (%s: %s).', $reason, $previous::class, $previous->getMessage());
        }
        if ($reason !== null) {
            return ': ' . $reason . '.';
        }
        if ($previous !== null) {
            return ': ' . $previous->getMessage();
        }

        return '.';
    }

    private static function formatFunctionName(ReflectionParameter $parameter): string
    {
        $function = $parameter->getDeclaringFunction();
        if ($function->isClosure()) {
            return 'Closure';
        }
        $class = $parameter->getDeclaringClass();
        if ($class !== null) {
            return sprintf('%s::%s()', $class->getName(), $function->getName());
        }

        return sprintf('%s()', $function->getName());
    }

    private function __construct() {}
}

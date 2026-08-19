<?php

declare(strict_types=1);

namespace Componenta\DI\Exception;

use ReflectionParameter;
use ReflectionProperty;
use RuntimeException;
use Throwable;

/**
 * Raised when the container cannot produce a value for a service, a parameter,
 * or a property.
 *
 * Diagnostic state is detached from live reflection/request/service objects so
 * an exception may be retained by logging or telemetry without retaining an
 * otherwise completed resolution graph.
 */
final class ResolutionException extends RuntimeException implements ExceptionInterface
{
    /**
     * @param array<string|int, string> $providedParameterTypes
     * @param array<int, string> $resolvedParameterTypes
     */
    public function __construct(
        string $message,
        public readonly ?string $parameterName = null,
        public readonly ?int $parameterPosition = null,
        public readonly ?string $parameterType = null,
        public readonly ?string $parameterContext = null,
        public readonly ?string $propertyName = null,
        public readonly ?string $propertyClass = null,
        public readonly ?string $propertyType = null,
        public readonly ?string $serviceId = null,
        public readonly array $providedParameterTypes = [],
        public readonly array $resolvedParameterTypes = [],
        public readonly ?string $actualType = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Parameter could not be resolved.
     *
     * @param array<string|int, mixed> $providedParameters
     * @param array<int, mixed> $resolvedParameters
     */
    public static function forParameter(
        ReflectionParameter $parameter,
        ?string $reason = null,
        array $providedParameters = [],
        array $resolvedParameters = [],
        ?Throwable $previous = null,
    ): self {
        $context = self::formatFunctionName($parameter);
        $type = $parameter->getType();

        return new self(
            sprintf(
                'Cannot resolve parameter "$%s" of %s%s',
                $parameter->getName(),
                $context,
                self::buildSuffix($reason, $previous),
            ),
            parameterName: $parameter->getName(),
            parameterPosition: $parameter->getPosition(),
            parameterType: $type === null ? null : (string) $type,
            parameterContext: $context,
            providedParameterTypes: self::valueTypes($providedParameters),
            resolvedParameterTypes: self::resolvedValueTypes($resolvedParameters),
            previous: $previous,
        );
    }

    /** Property could not be resolved. */
    public static function forProperty(
        ReflectionProperty $property,
        ?string $reason = null,
        ?Throwable $previous = null,
    ): self {
        $class = $property->getDeclaringClass()->getName();
        $type = $property->getType();

        return new self(
            sprintf(
                'Cannot resolve property "%s::$%s"%s',
                $class,
                $property->getName(),
                self::buildSuffix($reason, $previous),
            ),
            propertyName: $property->getName(),
            propertyClass: $class,
            propertyType: $type === null ? null : (string) $type,
            previous: $previous,
        );
    }

    /** A resolver failed while producing an entry. */
    public static function forService(string $id, Throwable $previous): self
    {
        return new self(
            sprintf('Failed to resolve service "%s": %s', $id, $previous->getMessage()),
            serviceId: $id,
            previous: $previous,
        );
    }

    /** The id refers to a class that does not exist and cannot be autowired. */
    public static function forMissingService(string $id): self
    {
        return new self(
            sprintf('Class "%s" does not exist and cannot be autowired.', $id),
            serviceId: $id,
        );
    }

    /** A resolver produced a non-object where an instance was expected. */
    public static function forNonObject(string $id, string $actualType): self
    {
        return new self(
            sprintf(
                'Service "%s" resolved to non-object of type "%s".',
                $id,
                $actualType,
            ),
            serviceId: $id,
            actualType: $actualType,
        );
    }

    private static function buildSuffix(?string $reason, ?Throwable $previous): string
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
        $class = $parameter->getDeclaringClass();

        if ($class !== null) {
            return sprintf('%s::%s()', $class->getName(), $function->getName());
        }

        if ($function->isClosure()) {
            return 'Closure';
        }

        return sprintf('%s()', $function->getName());
    }

    /**
     * @param array<string|int, mixed> $values
     * @return array<string|int, string>
     */
    private static function valueTypes(array $values): array
    {
        $types = [];
        foreach ($values as $key => $value) {
            $types[$key] = get_debug_type($value);
        }
        return $types;
    }

    /**
     * @param array<int, mixed> $values
     * @return array<int, string>
     */
    private static function resolvedValueTypes(array $values): array
    {
        /** @var array<int, string> $types */
        $types = [];
        foreach ($values as $position => $value) {
            $types[$position] = get_debug_type($value);
        }
        return $types;
    }
}

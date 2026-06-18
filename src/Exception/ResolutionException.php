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
 * Consolidates what used to be separated between `UnresolvableException` and
 * `FactoryException`: every "failed to build X" path - autowire miss, factory
 * throw, constructor parameter gap, property injection gap, missing class -
 * surfaces as a single type with named constructors discriminating the cause.
 *
 * Build instances through the {@see ::forParameter()}, {@see ::forProperty()},
 * {@see ::forService()}, {@see ::forMissingService()} and {@see ::forNonObject()}
 * factories; they produce Symfony-style messages and attach the relevant
 * reflection/service context as readonly fields.
 */
final class ResolutionException extends RuntimeException implements ExceptionInterface
{
    public function __construct(
        string $message,

        /**
         * Parameter that could not be resolved (parameter failures).
         */
        public readonly ?ReflectionParameter $parameter = null,

        /**
         * Property that could not be resolved (property failures).
         */
        public readonly ?ReflectionProperty $property = null,

        /**
         * Service id that failed to resolve (service failures).
         */
        public readonly ?string $serviceId = null,

        /**
         * Parameters provided by the caller at the moment of failure.
         *
         * @var array<string|int, mixed>
         */
        public readonly array $providedParameters = [],

        /**
         * Parameters already resolved when the failure happened.
         *
         * @var array<int, mixed>
         */
        public readonly array $resolvedParameters = [],

        /**
         * Actual runtime type returned by a resolver when a non-object was
         * produced where an object was required.
         */
        public readonly ?string $actualType = null,

        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Parameter could not be resolved.
     *
     * @param array<string|int, mixed> $providedParameters
     * @param array<int, mixed>        $resolvedParameters
     */
    public static function forParameter(
        ReflectionParameter $parameter,
        ?string $reason = null,
        array $providedParameters = [],
        array $resolvedParameters = [],
        ?Throwable $previous = null,
    ): self {
        $suffix = self::buildSuffix($reason, $previous);

        return new self(
            sprintf(
                'Cannot resolve parameter "$%s" of %s%s',
                $parameter->getName(),
                self::formatFunctionName($parameter),
                $suffix,
            ),
            parameter: $parameter,
            providedParameters: $providedParameters,
            resolvedParameters: $resolvedParameters,
            previous: $previous,
        );
    }

    /**
     * Property could not be resolved.
     */
    public static function forProperty(
        ReflectionProperty $property,
        ?string $reason = null,
        ?Throwable $previous = null,
    ): self {
        $suffix = self::buildSuffix($reason, $previous);

        return new self(
            sprintf(
                'Cannot resolve property "%s::$%s"%s',
                $property->getDeclaringClass()->getName(),
                $property->getName(),
                $suffix,
            ),
            property: $property,
            previous: $previous,
        );
    }

    /**
     * A resolver failed while producing the entry - factory threw, constructor
     * threw, reflection blew up, etc.
     */
    public static function forService(string $id, Throwable $previous): self
    {
        return new self(
            sprintf('Failed to resolve service "%s": %s', $id, $previous->getMessage()),
            serviceId: $id,
            previous: $previous,
        );
    }

    /**
     * The id refers to a class that does not exist and cannot be autowired.
     */
    public static function forMissingService(string $id): self
    {
        return new self(
            sprintf('Class "%s" does not exist and cannot be autowired.', $id),
            serviceId: $id,
        );
    }

    /**
     * A resolver produced a non-object where an instance was expected.
     */
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

    /**
     * Builds the trailing "": reason[ (previous: ...)]" fragment of a message.
     */
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
        $class    = $parameter->getDeclaringClass();

        if ($class !== null) {
            return sprintf('%s::%s()', $class->getName(), $function->getName());
        }

        if ($function->isClosure()) {
            return 'Closure';
        }

        return sprintf('%s()', $function->getName());
    }
}

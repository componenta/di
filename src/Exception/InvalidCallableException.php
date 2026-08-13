<?php

declare(strict_types=1);

namespace Componenta\DI\Exception;

use RuntimeException;
use Throwable;

/** Raised when a value cannot be normalized or resolved into a callable. */
final class InvalidCallableException extends RuntimeException implements CallableExceptionInterface
{
    public function __construct(
        public readonly mixed $callable,
        string $message = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** Value is not a valid callable and cannot be resolved into one. */
    public static function forValue(mixed $callable, ?Throwable $previous = null): self
    {
        return new self(
            $callable,
            sprintf(
                'Cannot convert value of type "%s" to a callable.',
                get_debug_type($callable),
            ),
            previous: $previous,
        );
    }

    /** Method does not exist on the given class. */
    public static function forMethod(string $class, string $method): self
    {
        return new self(
            [$class, $method],
            sprintf('Method "%s::%s()" does not exist.', $class, $method),
        );
    }

    /** Class is not invokable (no __invoke method). */
    public static function forNonInvokable(string $class): self
    {
        return new self(
            $class,
            sprintf('Class "%s" is not invokable (missing __invoke).', $class),
        );
    }

    /** Service required to build the callable is not in the container. */
    public static function forMissingService(string $id, ?Throwable $previous = null): self
    {
        return new self(
            $id,
            sprintf('Service "%s" is not defined in the container.', $id),
            previous: $previous,
        );
    }
}

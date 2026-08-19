<?php

declare(strict_types=1);

namespace Componenta\DI\Exception;

use RuntimeException;
use Throwable;

/** Raised when a value cannot be normalized or resolved into a callable. */
final class InvalidCallableException extends RuntimeException implements ExceptionInterface
{
    public function __construct(
        public readonly mixed $callable,
        string $message = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

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

    public static function forMethod(string $class, string $method): self
    {
        return new self(
            [$class, $method],
            sprintf('Method "%s::%s()" does not exist.', $class, $method),
        );
    }

    public static function forNonInvokable(string $class): self
    {
        return new self(
            $class,
            sprintf('Class "%s" is not invokable (missing __invoke).', $class),
        );
    }

    public static function forMissingService(string $id, ?Throwable $previous = null): self
    {
        return new self(
            $id,
            sprintf('Service "%s" is not defined in the container.', $id),
            previous: $previous,
        );
    }
}

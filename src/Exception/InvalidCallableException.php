<?php

declare(strict_types=1);

namespace Componenta\DI\Exception;

use Closure;
use RuntimeException;
use Throwable;

/** Raised when a value cannot be normalized or resolved into a callable. */
final class InvalidCallableException extends RuntimeException implements ExceptionInterface
{
    public readonly string $callableType;
    public readonly string $callableDescription;

    public function __construct(
        mixed $callable,
        string $message = '',
        ?Throwable $previous = null,
    ) {
        $this->callableType = get_debug_type($callable);
        $this->callableDescription = self::describe($callable);
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

    private static function describe(mixed $callable): string
    {
        if ($callable instanceof Closure) {
            return 'Closure';
        }

        if (is_string($callable)) {
            return $callable;
        }

        if (is_array($callable) && array_keys($callable) === [0, 1] && is_string($callable[1])) {
            $owner = is_object($callable[0])
                ? $callable[0]::class
                : (is_string($callable[0]) ? $callable[0] : get_debug_type($callable[0]));
            return $owner . '::' . $callable[1];
        }

        if (is_object($callable)) {
            return 'object ' . $callable::class;
        }

        return get_debug_type($callable);
    }
}

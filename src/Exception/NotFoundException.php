<?php

declare(strict_types=1);

namespace Componenta\DI\Exception;

use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;
use Throwable;

/** Thrown when an entry is not defined in the container. */
final class NotFoundException extends RuntimeException implements
    NotFoundExceptionInterface,
    ExceptionInterface
{
    public function __construct(
        public readonly string $id,
        string $message = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            $message !== '' ? $message : sprintf('Service "%s" is not defined in the container.', $id),
            0,
            $previous,
        );
    }

    public static function forService(string $id, ?Throwable $previous = null): self
    {
        return new self($id, previous: $previous);
    }
}

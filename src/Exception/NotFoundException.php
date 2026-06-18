<?php

declare(strict_types=1);

namespace Componenta\DI\Exception;

use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

/**
 * Thrown when an entry is not defined in the container.
 *
 * Implements {@see NotFoundExceptionInterface} so consumers relying on PSR-11
 * semantics can catch it independently of the container implementation.
 */
final class NotFoundException extends RuntimeException implements
    NotFoundExceptionInterface,
    ExceptionInterface
{
    public static function forService(string $id): self
    {
        return new self(
            sprintf('Service "%s" is not defined in the container.', $id),
        );
    }
}

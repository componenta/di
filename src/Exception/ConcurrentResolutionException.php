<?php

declare(strict_types=1);

namespace Componenta\DI\Exception;

use RuntimeException;

/** Raised when another execution context is already constructing a shared entry. */
final class ConcurrentResolutionException extends RuntimeException implements ExceptionInterface
{
    public static function forService(string $id): self
    {
        return new self(sprintf(
            'Shared service "%s" is already being resolved in another execution context; retry after that resolution completes.',
            $id,
        ));
    }
}

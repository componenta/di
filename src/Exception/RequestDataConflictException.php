<?php

declare(strict_types=1);

namespace Componenta\DI\Exception;

use InvalidArgumentException;
use Throwable;

/** Raised when request mapping cannot reconcile duplicate values for one key. */
final class RequestDataConflictException extends InvalidArgumentException implements ExceptionInterface
{
    public function __construct(
        public readonly string|int $key,
        public readonly string $existingSource,
        public readonly string $incomingSource,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                'Request data key "%s" is present in both %s and %s with different values.',
                (string) $key,
                $existingSource,
                $incomingSource,
            ),
            previous: $previous,
        );
    }
}

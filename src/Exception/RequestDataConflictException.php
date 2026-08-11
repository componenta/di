<?php

declare(strict_types=1);

namespace Componenta\DI\Exception;

use InvalidArgumentException;

/** Raised when request mapping receives different values for one key. */
final class RequestDataConflictException extends InvalidArgumentException
{
    public function __construct(
        public readonly string|int $key,
        public readonly string $existingSource,
        public readonly string $incomingSource,
    ) {
        parent::__construct(sprintf(
            'Request data key "%s" is present in both %s and %s with different values.',
            (string) $key,
            $existingSource,
            $incomingSource,
        ));
    }
}

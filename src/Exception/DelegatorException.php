<?php

declare(strict_types=1);

namespace Componenta\DI\Exception;

use RuntimeException;
use Throwable;

/**
 * Raised when a delegator (decorator callable) attached to an entry fails.
 *
 * Existing Componenta DI exceptions pass through unchanged. Any foreign
 * throwable, including a foreign PSR-11 container exception, is preserved as
 * the previous exception of this type.
 */
final class DelegatorException extends RuntimeException implements ExceptionInterface
{
    public function __construct(
        string $message,
        public readonly ?string $entryId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function forEntry(string $id, Throwable $previous): self
    {
        return new self(
            sprintf('Delegator for entry "%s" failed: %s', $id, $previous->getMessage()),
            entryId: $id,
            previous: $previous,
        );
    }
}

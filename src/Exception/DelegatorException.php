<?php

declare(strict_types=1);

namespace Componenta\DI\Exception;

use RuntimeException;
use Throwable;

/**
 * Raised when a delegator (decorator callable) attached to an entry fails.
 *
 * Container-typed and PSR-11 failures surface unchanged through the delegator
 * chain; only foreign exceptions are wrapped by this type so callers can tell
 * "the delegator itself misbehaved" apart from "the delegator re-raised a
 * container failure".
 */
final class DelegatorException extends RuntimeException implements ExceptionInterface
{
    public function __construct(
        string $message,

        /**
         * Entry id whose delegator chain failed.
         */
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

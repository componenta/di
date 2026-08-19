<?php

declare(strict_types=1);

namespace Componenta\DI\Exception;

use RuntimeException;
use Throwable;

/** Raised when DI cache or generated factory artifacts cannot be produced or activated. */
final class CompilationException extends RuntimeException implements ExceptionInterface
{
    public static function forArtifact(string $artifact, Throwable $previous): self
    {
        return new self(
            sprintf('Failed to compile DI artifact "%s": %s', $artifact, $previous->getMessage()),
            previous: $previous,
        );
    }
}

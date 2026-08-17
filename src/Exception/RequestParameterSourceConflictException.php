<?php

declare(strict_types=1);

namespace Componenta\DI\Exception;

use InvalidArgumentException;

/** Raised when mapped request data tries to bind a source-bound DTO parameter. */
final class RequestParameterSourceConflictException extends InvalidArgumentException implements ExceptionInterface
{
    /**
     * @param class-string $dtoClass
     * @param class-string $source
     */
    public function __construct(
        public readonly string $dtoClass,
        public readonly string $key,
        public readonly string $source,
    ) {
        parent::__construct(sprintf(
            'Mapped request data key "%s" conflicts with declared source "%s" for $%s of %s.',
            $key,
            $source,
            $key,
            $dtoClass,
        ));
    }
}

<?php

declare(strict_types=1);

namespace Componenta\DI\Exception;

use InvalidArgumentException;

/** Caller or mapped input tried to occupy a target owned by a declared provider. */
final class ValueProviderConflictException extends InvalidArgumentException implements ExceptionInterface
{
    /** @param class-string $provider */
    public function __construct(
        public readonly string $target,
        public readonly string $provider,
        public readonly string $key,
        public readonly string $origin,
    ) {
        parent::__construct(sprintf(
            'Value target %s is owned by provider "#[%s]"; %s input key "%s" may not bind it.',
            $target,
            $provider,
            $origin,
            $key,
        ));
    }
}

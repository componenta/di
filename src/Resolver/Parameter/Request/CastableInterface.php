<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter\Request;

interface CastableInterface
{
    /**
     * Cast type for the extracted value.
     * If null, no casting is applied.
     */
    public ?string $cast { get; }
}

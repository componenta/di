<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter\Request;

interface CastableInterface
{
    public ?string $cast { get; }
}

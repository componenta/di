<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter\Request;

use Closure;

/** Optional DI-owned transformation after mapping and before DTO creation. */
interface CallbackMapperInterface extends MapperInterface
{
    /** Stable callback configuration for the current mapper instance. */
    public ?Closure $afterMap { get; }
}

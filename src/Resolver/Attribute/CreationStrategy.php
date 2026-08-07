<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Attribute;

/** How a reflected object should be materialized. */
enum CreationStrategy: string
{
    case Eager = 'eager';
    case Lazy = 'lazy';
    case Proxy = 'proxy';
}

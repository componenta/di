<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

use Attribute;
use Closure;

/** Transforms a resolved value, autowiring any additional callback parameters. */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final readonly class Transform
{
    public function __construct(public Closure $callback) {}
}

<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

use Attribute;

/** Applies a named caster to an already resolved value. */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final readonly class Cast
{
    public function __construct(public string $name) {}
}

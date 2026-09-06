<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

use Attribute;
use Componenta\Config\DefaultValue;

/** Casts a resolved parameter/property value with a named caster. */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class Cast
{
    public function __construct(
        public string $name,
        public mixed $default = DefaultValue::None,
    ) {}
}

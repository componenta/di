<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

use Attribute;
use Componenta\Config\DefaultValue;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Cookie
{
    public function __construct(
        public string $name,
        public mixed $default = DefaultValue::None,
    ) {}
}

<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

use Attribute;
use Componenta\Config\ConfigPath;
use Componenta\Config\DefaultValue;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class PayloadParam
{
    public function __construct(
        public string|ConfigPath|null $name = null,
        public mixed $default = DefaultValue::None,
    ) {}
}

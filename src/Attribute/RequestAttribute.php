<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

use Attribute;
use Componenta\Config\DefaultValue;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class RequestAttribute
{
    public function __construct(
        public ?string $name = null,
        public mixed $default = DefaultValue::None,
    ) {}
}

<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

use Attribute;
use Componenta\Config\DefaultValue;

/** Provides a raw environment value. Use #[Cast] for conversion. */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class Env
{
    public function __construct(
        public ?string $name = null,
        public mixed $default = DefaultValue::None,
    ) {}
}

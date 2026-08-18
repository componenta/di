<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

use Attribute;
use Componenta\Config\ConfigPath;
use Componenta\Config\DefaultValue;

/** Provides a value from application configuration. */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class Config
{
    public const string KEY = 'config';

    public function __construct(
        public string|ConfigPath|null $path = null,
        public mixed $default = DefaultValue::None,
    ) {}
}

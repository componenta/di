<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

use Attribute;
use Componenta\Config\DefaultValue;
use Componenta\DI\Resolver\Parameter\ParameterSourceAttributeInterface;

/** Provides an environment value, converted for scalar/array target types. */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class Env implements ParameterSourceAttributeInterface
{
    public function __construct(
        public ?string $name = null,
        public mixed $default = DefaultValue::None,
    ) {}
}

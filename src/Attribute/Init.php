<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

use Attribute;

/** Initializes a property value by executing a DI-aware callable. */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Init
{
    /** @param array<string|int, mixed> $params */
    public function __construct(
        public mixed $callable,
        public array $params = [],
    ) {}
}

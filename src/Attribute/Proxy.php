<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

use Attribute;

/** Marks a class or injection point for native virtual-proxy creation. */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class Proxy
{
    /** @param class-string|null $class Concrete proxy class for an injection point. */
    public function __construct(public ?string $class = null) {}
}

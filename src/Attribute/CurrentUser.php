<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

use Attribute;
use Componenta\DI\Resolver\Parameter\ParameterSourceAttributeInterface;

/** Provides the current authenticated user. */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class CurrentUser implements ParameterSourceAttributeInterface
{
    /** @param class-string|null $type */
    public function __construct(public ?string $type = null) {}
}

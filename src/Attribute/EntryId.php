<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

use Attribute;

/** Provides a value from an explicit container entry id. */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class EntryId
{
    public function __construct(public string $value) {}
}

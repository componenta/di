<?php

declare(strict_types=1);

namespace Componenta\DI\Value;

/** Distinguishes a successfully resolved null from an unresolved fallback. */
final readonly class ValueResult
{
    public function __construct(public mixed $value) {}
}

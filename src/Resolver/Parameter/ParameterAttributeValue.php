<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

/** Immutable value state passed between composed parameter attribute handlers. */
final readonly class ParameterAttributeValue
{
    private function __construct(
        public bool $resolved,
        public mixed $value = null,
    ) {}

    public static function unresolved(): self
    {
        return new self(false);
    }

    public static function resolved(mixed $value): self
    {
        return new self(true, $value);
    }
}

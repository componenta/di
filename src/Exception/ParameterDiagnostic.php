<?php

declare(strict_types=1);

namespace Componenta\DI\Exception;

/** Detached parameter metadata safe to retain with an exception. */
final readonly class ParameterDiagnostic
{
    public function __construct(
        public string $name,
        public int $position,
        public ?string $type,
        public string $context,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getPosition(): int
    {
        return $this->position;
    }
}

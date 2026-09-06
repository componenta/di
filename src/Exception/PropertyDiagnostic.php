<?php

declare(strict_types=1);

namespace Componenta\DI\Exception;

/** Detached property metadata safe to retain with an exception. */
final readonly class PropertyDiagnostic
{
    public function __construct(
        public string $name,
        public string $class,
        public ?string $type,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }
}

<?php

declare(strict_types=1);

namespace Componenta\DI;

use Psr\Http\Message\ServerRequestInterface;

/** Immutable provenance-aware input for one DI resolution operation. */
final readonly class ResolutionContext
{
    /**
     * @param array<string|int, mixed> $explicit
     * @param array<string, mixed> $mapped
     * @param array<string, mixed> $trusted
     */
    public function __construct(
        public array $explicit = [],
        public array $mapped = [],
        public array $trusted = [],
    ) {}

    /** @param array<string|int, mixed> $values */
    public static function explicit(array $values = []): self
    {
        return new self(explicit: $values);
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, mixed> $trusted
     */
    public static function mapped(
        array $values,
        ?ServerRequestInterface $request = null,
        array $trusted = [],
    ): self {
        if ($request !== null) {
            $trusted[ServerRequestInterface::class] = $request;
        }

        return new self(mapped: $values, trusted: $trusted);
    }

    /** @param array<string|int, mixed> $values */
    public function withExplicit(array $values): self
    {
        return new self(
            explicit: array_replace($this->explicit, $values),
            mapped: $this->mapped,
            trusted: $this->trusted,
        );
    }

    /** Nested service construction keeps trusted framework context but not unrelated mapped DTO fields. */
    public function nested(array $explicit = []): self
    {
        return new self(explicit: $explicit, trusted: $this->trusted);
    }

    /** @return array<string|int, mixed> */
    public function visible(): array
    {
        return array_replace($this->mapped, $this->trusted, $this->explicit);
    }

    public function request(): ?ServerRequestInterface
    {
        $request = $this->trusted[ServerRequestInterface::class]
            ?? $this->explicit[ServerRequestInterface::class]
            ?? null;

        return $request instanceof ServerRequestInterface ? $request : null;
    }

    public function hasMapped(string $key): bool
    {
        return array_key_exists($key, $this->mapped);
    }
}

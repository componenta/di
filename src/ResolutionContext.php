<?php

declare(strict_types=1);

namespace Componenta\DI;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Immutable provenance-aware input for one DI resolution operation.
 *
 * Explicit values are trusted caller overrides. Mapped values originate from
 * request DTO mapping and never acquire explicit-override authority. Trusted
 * values carry framework-owned context such as the current PSR-7 request.
 */
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

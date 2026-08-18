<?php

declare(strict_types=1);

namespace Componenta\DI;

use Psr\Http\Message\ServerRequestInterface;

/** Immutable, provenance-aware input to every DI resolution operation. */
final readonly class ResolutionContext
{
    /** @var array<string|int, mixed> */
    public array $explicit;

    /** @var array<string, mixed> */
    public array $mapped;

    /** @var array<string|int, mixed> */
    public array $trusted;

    /**
     * @param array<string|int, mixed> $explicit
     * @param array<string, mixed> $mapped
     * @param array<string|int, mixed> $trusted
     */
    public function __construct(
        array $explicit = [],
        array $mapped = [],
        array $trusted = [],
    ) {
        $this->explicit = $explicit;
        $this->mapped = $mapped;
        $this->trusted = $trusted;
    }

    /** @param array<string|int, mixed> $values */
    public static function explicit(array $values): self
    {
        return new self(explicit: $values);
    }

    /**
     * Adapts the v4 `call(..., $params)` convention without erasing provenance.
     * The PSR-7 request used to travel under its interface key and is framework
     * context rather than a caller override, so it is promoted to trusted input.
     * Every other legacy value remains explicit.
     *
     * @param array<string|int, mixed> $values
     */
    public static function fromLegacyParameters(array $values): self
    {
        $explicit = $values;
        $trusted = [];
        $request = $explicit[ServerRequestInterface::class] ?? null;

        if ($request instanceof ServerRequestInterface) {
            unset($explicit[ServerRequestInterface::class]);
            $trusted[ServerRequestInterface::class] = $request;
        }

        return new self(explicit: $explicit, trusted: $trusted);
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string|int, mixed> $trusted
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

    /** @param array<string, mixed> $values */
    public function withMapped(array $values): self
    {
        return new self(
            explicit: $this->explicit,
            mapped: array_replace($this->mapped, $values),
            trusted: $this->trusted,
        );
    }

    /** @param array<string|int, mixed> $values */
    public function withTrusted(array $values): self
    {
        return new self(
            explicit: $this->explicit,
            mapped: $this->mapped,
            trusted: array_replace($this->trusted, $values),
        );
    }

    /** @return array<string|int, mixed> */
    public function visible(): array
    {
        return array_replace($this->mapped, $this->trusted, $this->explicit);
    }

    public function hasMapped(string $key): bool
    {
        return array_key_exists($key, $this->mapped);
    }

    public function request(): ?ServerRequestInterface
    {
        $request = $this->trusted[ServerRequestInterface::class] ?? null;
        return $request instanceof ServerRequestInterface ? $request : null;
    }
}

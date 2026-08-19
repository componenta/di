<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

/**
 * Two-tier entry cache used internally by the container.
 *
 * @internal
 */
final class EntryCache
{
    /** @var array<string, mixed> */
    private array $base = [];

    /** @var array<string, mixed> */
    private array $resolved = [];

    /** @var array<string, array<string, true>> */
    private array $reverseIndex = [];

    /** @param array<string, mixed> $base */
    public function __construct(array $base = [])
    {
        $this->base = $base;
    }

    public function tryGetBase(string $id, mixed &$value): bool
    {
        if (isset($this->base[$id])) {
            $value = $this->base[$id];
            return true;
        }

        if (!array_key_exists($id, $this->base)) {
            return false;
        }

        $value = null;
        return true;
    }

    public function putBase(string $id, mixed $value): void
    {
        $this->base[$id] = $value;
    }

    public function removeBase(string $id): void
    {
        unset($this->base[$id]);
    }

    public function tryGetResolved(string $id, mixed &$value): bool
    {
        if (isset($this->resolved[$id])) {
            $value = $this->resolved[$id];
            return true;
        }

        if (!array_key_exists($id, $this->resolved)) {
            return false;
        }

        $value = null;
        return true;
    }

    public function putResolved(string $requestedId, string $canonicalId, mixed $value): void
    {
        $this->resolved[$requestedId] = $value;

        if ($canonicalId !== $requestedId) {
            $this->reverseIndex[$canonicalId][$requestedId] = true;
        }
    }

    public function invalidate(string $requestedId, ?string $canonicalId = null): void
    {
        unset($this->resolved[$requestedId]);

        $canonical = $canonicalId ?? $requestedId;

        if ($canonical !== $requestedId) {
            unset($this->resolved[$canonical]);
        }

        if (isset($this->reverseIndex[$canonical])) {
            foreach ($this->reverseIndex[$canonical] as $sibling => $_) {
                unset($this->resolved[$sibling]);
            }
            unset($this->reverseIndex[$canonical]);
        }
    }
}

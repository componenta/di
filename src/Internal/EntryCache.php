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

    /** @var array<string, int> */
    private array $baseRevisions = [];

    /** @var array<string, int> */
    private array $resolvedRevisions = [];

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
        $this->baseRevisions[$id] = $this->baseRevision($id) + 1;
    }

    public function baseRevision(string $id): int
    {
        return $this->baseRevisions[$id] ?? 0;
    }

    public function resolvedRevision(string $id): int
    {
        return $this->resolvedRevisions[$id] ?? 0;
    }

    public function removeBase(string $id): void
    {
        unset($this->base[$id]);
        $this->baseRevisions[$id] = $this->baseRevision($id) + 1;
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
        $this->invalidateResolved($requestedId);

        $canonical = $canonicalId ?? $requestedId;

        if ($canonical !== $requestedId) {
            $this->invalidateResolved($canonical);
        }

        if (isset($this->reverseIndex[$canonical])) {
            foreach ($this->reverseIndex[$canonical] as $sibling => $_) {
                $this->invalidateResolved((string) $sibling);
            }
            unset($this->reverseIndex[$canonical]);
        }
    }

    private function invalidateResolved(string $id): void
    {
        unset($this->resolved[$id]);
        $this->resolvedRevisions[$id] = $this->resolvedRevision($id) + 1;
    }
}

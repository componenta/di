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

    public function replaceBase(string $id, mixed $value, MutationRetirement $retirement): void
    {
        if (array_key_exists($id, $this->base)) {
            $retirement->retain($this->base[$id]);
        }
        $this->putBase($id, $value);
    }

    public function baseRevision(string $id): int
    {
        return $this->baseRevisions[$id] ?? 0;
    }

    public function resolvedRevision(string $id): int
    {
        return $this->resolvedRevisions[$id] ?? 0;
    }

    public function removeBase(string $id, MutationRetirement $retirement): void
    {
        if (array_key_exists($id, $this->base)) {
            $retirement->retain($this->base[$id]);
        }
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

    public function invalidate(string $requestedId, ?string $canonicalId, MutationRetirement $retirement): void
    {
        $this->invalidateResolved($requestedId, $retirement);

        $canonical = $canonicalId ?? $requestedId;

        if ($canonical !== $requestedId) {
            $this->invalidateResolved($canonical, $retirement);
        }

        if (isset($this->reverseIndex[$canonical])) {
            foreach ($this->reverseIndex[$canonical] as $sibling => $_) {
                $this->invalidateResolved((string) $sibling, $retirement);
            }
            unset($this->reverseIndex[$canonical]);
        }
    }

    /** Invalidates one requested pipeline without changing its shared source. */
    public function invalidateRequested(string $requestedId, string $canonicalId, MutationRetirement $retirement): void
    {
        $this->invalidateResolved($requestedId, $retirement);
        unset($this->reverseIndex[$canonicalId][$requestedId]);
        if (($this->reverseIndex[$canonicalId] ?? []) === []) {
            unset($this->reverseIndex[$canonicalId]);
        }
    }

    private function invalidateResolved(string $id, MutationRetirement $retirement): void
    {
        if (array_key_exists($id, $this->resolved)) {
            $retirement->retain($this->resolved[$id]);
        }
        unset($this->resolved[$id]);
        $this->resolvedRevisions[$id] = $this->resolvedRevision($id) + 1;
    }
}

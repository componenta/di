<?php

declare(strict_types=1);

namespace Componenta\DI;

/**
 * Two-tier entry cache used internally by {@see Container}.
 *
 * The container maintains two distinct layers:
 *
 *  - **Base** - values keyed by canonical (post-alias) id. Populated by
 *    self-registered services and cached resolver output. Undecorated.
 *  - **Resolved** - final (delegator-decorated) values keyed by *requested*
 *    id. The same base entry can be exposed under multiple requested ids
 *    (one per alias); the {@see self::$reverseIndex} tracks which requested
 *    ids were last served from which canonical id so invalidation stays
 *    coherent on both sides.
 *
 * Intended for container-internal use - no external consumers.
 *
 * @internal
 */
final class EntryCache
{
    /** @var array<string, mixed> Base entries by canonical id. */
    private array $base = [];

    /** @var array<string, mixed> Decorated entries by requested id. */
    private array $resolved = [];

    /** @var array<string, array<string, true>> Canonical id -> set of requested ids that mapped to it. */
    private array $reverseIndex = [];

    /** @param array<string, mixed> $base */
    public function __construct(array $base = [])
    {
        $this->base = $base;
    }

    /**
     * Reads a base entry in one cache operation while preserving stored nulls.
     */
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

    /**
     * Reads a decorated entry in one cache operation while preserving stored nulls.
     */
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

    /**
     * Stores the final (decorated) value for `$requestedId` and records the
     * `$canonicalId -> $requestedId` mapping when they differ, so a later
     * invalidation of either side can wipe its twin.
     *
     * The reverse-index uses requested ids as associative keys (value `true`)
     * to avoid accumulating duplicates when the same alias is resolved more
     * than once (e.g. after partial invalidations).
     */
    public function putResolved(string $requestedId, string $canonicalId, mixed $value): void
    {
        $this->resolved[$requestedId] = $value;

        if ($canonicalId !== $requestedId) {
            $this->reverseIndex[$canonicalId][$requestedId] = true;
        }
    }

    /**
     * Invalidates both sides of the cache for the given id.
     *
     * `$canonicalId` is the post-alias target. When the caller cannot resolve
     * the alias (e.g. malformed alias map), it may pass `null` and only the
     * one-sided invalidation is performed.
     */
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

<?php

declare(strict_types=1);

namespace Componenta\DI;

use Componenta\DI\Exception\CircularDependencyException;
use Componenta\DI\Exception\InvalidConfigurationException;

/**
 * Resolves aliases to their target identifiers.
 *
 * Uses internal cache for resolved alias chains to achieve O(1)
 * lookups after first resolution.
 */
final class AliasResolver implements AliasResolverInterface, \IteratorAggregate
{
    /**
     * Cache of fully resolved aliases.
     * @var array<string, string>
     */
    private array $resolved = [];

    /** @var array<string, string> */
    private array $map;

    /**
     * @param array<string, string> $map Alias to target map.
     * @param bool $skipValidation Skip circular reference validation.
     */
    public function __construct(
        array $map = [],
        bool $skipValidation = false,
    ) {
        if ($skipValidation) {
            $this->map = $map;

            return;
        }

        // Incremental validation: replay the map as a series of insertions.
        // This gives O(total alias-chain length) instead of O(N²) per insert.
        $this->map = [];
        foreach ($map as $alias => $target) {
            if ($alias === $target) {
                throw InvalidConfigurationException::forSelfReferencingAlias($alias);
            }

            $this->assertNoCycle($alias, $target);
            $this->map[$alias] = $target;
        }
    }

    public function resolve(string $id): string
    {
        if (!isset($this->map[$id])) {
            return $id;
        }

        // Return cached resolution
        if (isset($this->resolved[$id])) {
            return $this->resolved[$id];
        }

        // Resolve chain with cycle guard (defensive: validate() may have been skipped)
        $current = $this->map[$id];
        $visited = [$id => true, $current => true];

        while (isset($this->map[$current])) {
            $next = $this->map[$current];

            if (isset($visited[$next])) {
                $path = [...array_keys($visited), $next];
                throw CircularDependencyException::forAlias($path);
            }

            $visited[$next] = true;
            $current = $next;
        }

        return $this->resolved[$id] = $current;
    }

    public function set(string $alias, string $target): static
    {
        if ($alias === $target) {
            throw InvalidConfigurationException::forSelfReferencingAlias($alias);
        }

        $this->assertNoCycle($alias, $target);
        $this->map[$alias] = $target;

        // Clear cache - alias chain changed
        $this->resolved = [];

        return $this;
    }

    public function has(string $alias): bool
    {
        return isset($this->map[$alias]);
    }

    public function unset(string $alias): static
    {
        unset($this->map[$alias]);

        // Clear cache - alias chain changed
        $this->resolved = [];

        return $this;
    }

    public function getIterator(): \Traversable
    {
        yield from $this->map;
    }

    /**
     * Ensures that inserting `$alias -> $target` into the current map does not
     * create a cycle. O(length of the chain starting at $target); for a
     * cycle-free input map this is bounded by the tree depth, not N.
     *
     * @throws CircularDependencyException If a cycle would be created.
     */
    private function assertNoCycle(string $alias, string $target): void
    {
        $path    = [$alias, $target];
        $current = $target;

        while (isset($this->map[$current])) {
            $next   = $this->map[$current];
            $path[] = $next;

            if ($next === $alias) {
                throw CircularDependencyException::forAlias($path);
            }

            $current = $next;
        }
    }
}

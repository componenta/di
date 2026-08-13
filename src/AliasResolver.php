<?php

declare(strict_types=1);

namespace Componenta\DI;

use Componenta\DI\Exception\CircularDependencyException;
use Componenta\DI\Exception\InvalidConfigurationException;

/**
 * Resolves aliases to their target identifiers.
 *
 * Uses path compression so every alias encountered in a successfully resolved
 * chain becomes an O(1) lookup until the map changes.
 */
final class AliasResolver implements AliasResolverInterface
{
    /** @var array<string, string> Cache of fully resolved aliases. */
    private array $resolved = [];

    /** @var array<string, string> */
    private array $map;

    /** @param array<string, string> $map Alias to target map. */
    public function __construct(array $map = [])
    {
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

        if (isset($this->resolved[$id])) {
            return $this->resolved[$id];
        }

        $current = $id;
        $path = [];
        $visited = [];

        while (isset($this->map[$current])) {
            $path[] = $current;
            $visited[$current] = true;
            $next = $this->map[$current];

            if (isset($visited[$next])) {
                throw CircularDependencyException::forAlias([...$path, $next]);
            }

            if (isset($this->resolved[$next])) {
                $current = $this->resolved[$next];
                break;
            }

            $current = $next;
        }

        foreach ($path as $alias) {
            $this->resolved[$alias] = $current;
        }

        return $current;
    }

    public function set(string $alias, string $target): static
    {
        if ($alias === $target) {
            throw InvalidConfigurationException::forSelfReferencingAlias($alias);
        }

        $this->assertNoCycle($alias, $target);
        $this->map[$alias] = $target;
        $this->resolved = [];

        return $this;
    }

    public function has(string $alias): bool
    {
        return isset($this->map[$alias]);
    }

    /**
     * Ensures that inserting `$alias -> $target` into the current map does not
     * create a cycle.
     *
     * @throws CircularDependencyException If a cycle would be created.
     */
    private function assertNoCycle(string $alias, string $target): void
    {
        $path = [$alias, $target];
        $visited = [$alias => true, $target => true];
        $current = $target;

        while (isset($this->map[$current])) {
            $next = $this->map[$current];
            $path[] = $next;

            if (isset($visited[$next])) {
                throw CircularDependencyException::forAlias($path);
            }

            $visited[$next] = true;
            $current = $next;
        }
    }
}

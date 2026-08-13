<?php

declare(strict_types=1);

namespace Componenta\DI;

use Componenta\DI\Exception\CircularDependencyException;
use Componenta\DI\Exception\InvalidConfigurationException;

/**
 * Resolves aliases to their target identifiers.
 */
interface AliasResolverInterface
{
    /**
     * Resolves an identifier to its target.
     *
     * @throws CircularDependencyException If a malformed mutable alias graph
     *                                     is encountered defensively.
     */
    public function resolve(string $id): string;

    /**
     * Registers an alias.
     *
     * @throws InvalidConfigurationException If alias equals target.
     * @throws CircularDependencyException   If the mapping creates a cycle.
     */
    public function set(string $alias, string $target): static;

    /**
     * Checks if an alias exists.
     */
    public function has(string $alias): bool;
}

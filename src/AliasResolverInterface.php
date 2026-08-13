<?php

declare(strict_types=1);

namespace Componenta\DI;

use Componenta\DI\Exception\CircularDependencyException;

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
     * Checks if an alias exists.
     */
    public function has(string $alias): bool;
}

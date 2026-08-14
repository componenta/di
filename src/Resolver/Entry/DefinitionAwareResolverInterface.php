<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\DI\Definition\DefinitionInterface;
use Componenta\DI\Exception\InvalidConfigurationException;

/**
 * Entry resolver that owns mutable runtime definitions.
 *
 * Registration and removal form one capability: any definition installed at
 * runtime through {@see \Componenta\DI\Container::set()} must be removable
 * again when that id is replaced by a stored value. Removal is idempotent and
 * must not remove bindings that were part of the resolver's static/configured
 * state.
 *
 * Separated from {@see EntryResolverInterface} so resolvers that only know how
 * to resolve entries (for example ReflectionResolver) do not implement
 * definition mutation methods they do not own.
 */
interface DefinitionAwareResolverInterface extends EntryResolverInterface
{
    /**
     * Registers or replaces a runtime definition for an entry.
     *
     * @throws InvalidConfigurationException If the definition type is not supported.
     */
    public function setDefinition(string $id, DefinitionInterface $definition): void;

    /**
     * Removes runtime definitions previously registered for the id.
     *
     * This operation is idempotent. Configured/static bindings must remain
     * untouched when no runtime definition is registered for the id.
     */
    public function removeDefinition(string $id): void;

    /** Checks whether this resolver supports the given definition type. */
    public function supportsDefinition(DefinitionInterface $definition): bool;
}

<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\DI\Definition\DefinitionInterface;
use Componenta\DI\Exception\InvalidConfigurationException;

/**
 * Entry resolver that accepts explicit definitions as configuration.
 *
 * Definitions are not a separate runtime layer. A definition registered
 * through Container::set() configures the same resolver state that is filled
 * from declarative factories/invokables during container construction.
 *
 * Separated from {@see EntryResolverInterface} so resolvers that only know how
 * to resolve entries (for example ReflectionResolver) do not implement
 * definition configuration methods they do not own.
 */
interface DefinitionAwareResolverInterface extends EntryResolverInterface
{
    /**
     * Registers or replaces a definition for an entry.
     *
     * @throws InvalidConfigurationException If the definition type is not supported.
     */
    public function setDefinition(string $id, DefinitionInterface $definition): void;

    /** Checks whether this resolver supports the given definition type. */
    public function supportsDefinition(DefinitionInterface $definition): bool;
}

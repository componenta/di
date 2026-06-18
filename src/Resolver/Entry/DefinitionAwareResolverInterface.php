<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\DI\Definition\DefinitionInterface;
use Componenta\DI\Exception\InvalidConfigurationException;

/**
 * Entry resolver that accepts explicit {@see DefinitionInterface} instances
 * at runtime (e.g. via {@see \Componenta\DI\Container::set()}).
 *
 * Separated from {@see EntryResolverInterface} (ISP) so resolvers that only
 * know how to resolve by autowiring (e.g. ReflectionResolver) do not have to
 * implement empty no-ops for definition handling.
 */
interface DefinitionAwareResolverInterface extends EntryResolverInterface
{
    /**
     * Registers a definition for an entry.
     *
     * @throws InvalidConfigurationException If the definition type is not supported.
     */
    public function setDefinition(string $id, DefinitionInterface $definition): void;

    /**
     * Checks whether this resolver supports the given definition type.
     */
    public function supportsDefinition(DefinitionInterface $definition): bool;
}

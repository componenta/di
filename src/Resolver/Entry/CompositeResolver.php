<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\DI\Definition\DefinitionInterface;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Exception\ResolutionException;

/**
 * Combines multiple entry resolvers into a resolution chain.
 *
 * Resolvers are tried in order of registration. The first resolver whose
 * {@see EntryResolverInterface::can()} returns true wins.
 *
 * The composite itself is {@see DefinitionAwareResolverInterface}: definition
 * handling is delegated to child resolvers that also implement the
 * definition-aware contract; pure {@see EntryResolverInterface} resolvers
 * (e.g. autowiring-only) are skipped for definition operations.
 *
 * Performance: the id -> owning-resolver mapping is cached so a typical
 * `has($id)` followed by `get($id)` scans each child resolver at most once.
 * Cached resolution is preserved across {@see self::can()} and
 * {@see self::resolve()} - there is no double-scan of the chain.
 */
class CompositeResolver implements DefinitionAwareResolverInterface
{
    /** @var list<EntryResolverInterface> */
    protected array $resolvers = [];

    /**
     * Cache of id -> resolver mapping.
     *
     * An entry with value null means the composite has confirmed no child
     * resolver can handle this id (negative cache). A non-null value means
     * that resolver is the owner; its {@see EntryResolverInterface::resolve()}
     * will be called directly without re-scanning the chain.
     *
     * @var array<string, EntryResolverInterface|null>
     */
    private array $ownerCache = [];

    /**
     * Adds a resolver to the chain.
     */
    public function addResolver(EntryResolverInterface $resolver): void
    {
        $this->resolvers[] = $resolver;
        $this->ownerCache = [];
    }

    /**
     * Checks if any resolver can handle the given entry.
     */
    public function can(string $id): bool
    {
        return $this->findOwner($id) !== null;
    }

    /**
     * Resolves an entry using the first matching resolver.
     *
     * @throws NotFoundException     If no resolver can handle the entry.
     * @throws ResolutionException If a resolver fails during resolution.
     */
    public function resolve(string $id, array $context = []): mixed
    {
        $owner = $this->findOwner($id);

        if ($owner === null) {
            throw NotFoundException::forService($id);
        }

        return $owner->resolve($id, $context);
    }

    /**
     * Delegates definition to the first supporting definition-aware resolver.
     *
     * @throws InvalidConfigurationException If no resolver supports the definition type.
     */
    public function setDefinition(string $id, DefinitionInterface $definition): void
    {
        foreach ($this->resolvers as $resolver) {
            if ($resolver instanceof DefinitionAwareResolverInterface
                && $resolver->supportsDefinition($definition)
            ) {
                $resolver->setDefinition($id, $definition);
                unset($this->ownerCache[$id]);
                return;
            }
        }

        throw InvalidConfigurationException::forInvalidDefinition($definition);
    }

    /**
     * Checks if any definition-aware resolver supports the given definition.
     */
    public function supportsDefinition(DefinitionInterface $definition): bool
    {
        foreach ($this->resolvers as $resolver) {
            if ($resolver instanceof DefinitionAwareResolverInterface
                && $resolver->supportsDefinition($definition)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the resolver that claims ownership of the given id, using the
     * cache when possible.
     */
    private function findOwner(string $id): ?EntryResolverInterface
    {
        if (array_key_exists($id, $this->ownerCache)) {
            return $this->ownerCache[$id];
        }

        foreach ($this->resolvers as $resolver) {
            if ($resolver->can($id)) {
                return $this->ownerCache[$id] = $resolver;
            }
        }

        return $this->ownerCache[$id] = null;
    }
}

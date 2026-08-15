<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\DI\Definition\DefinitionInterface;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Exception\ResolutionException;
use InvalidArgumentException;

/** Ordered entry-resolver chain with positive and negative owner caching. */
class CompositeResolver implements DefinitionAwareResolverInterface
{
    /** @var list<EntryResolverInterface> */
    protected array $resolvers = [];

    /** @var array<string, EntryResolverInterface|null> */
    private array $ownerCache = [];

    /**
     * Definitions registered through this composite explicitly select their
     * supporting resolver. This keeps the latest definition authoritative when
     * the same id is reconfigured with a different definition kind.
     *
     * @var array<string, DefinitionAwareResolverInterface>
     */
    private array $definitionOwners = [];

    public function __construct(EntryResolverInterface ...$resolvers)
    {
        if (!array_is_list($resolvers)) {
            $resolvers = array_values($resolvers);
        }

        if ($resolvers === []) {
            return;
        }

        $registered = [];

        foreach ($resolvers as $resolver) {
            $objectId = spl_object_id($resolver);
            if (isset($registered[$objectId])) {
                throw new InvalidArgumentException(sprintf(
                    'Entry resolver %s is already registered.',
                    $resolver::class,
                ));
            }

            $registered[$objectId] = true;
        }

        $this->resolvers = $resolvers;
    }

    public function addResolver(EntryResolverInterface $resolver): void
    {
        $this->assertNotRegistered($resolver);
        $this->resolvers[] = $resolver;
        $this->ownerCache = [];
    }

    public function can(string $id): bool
    {
        return $this->findOwner($id) !== null;
    }

    /**
     * @throws NotFoundException
     * @throws ResolutionException
     */
    public function resolve(string $id, array $context = []): mixed
    {
        $owner = $this->findOwner($id);

        if ($owner === null) {
            throw NotFoundException::forService($id);
        }

        return $owner->resolve($id, $context);
    }

    public function setDefinition(string $id, DefinitionInterface $definition): void
    {
        foreach ($this->resolvers as $resolver) {
            if ($resolver instanceof DefinitionAwareResolverInterface
                && $resolver->supportsDefinition($definition)
            ) {
                $resolver->setDefinition($id, $definition);
                $this->definitionOwners[$id] = $resolver;
                $this->ownerCache[$id] = $resolver;
                return;
            }
        }

        throw InvalidConfigurationException::forInvalidDefinition($definition);
    }

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

    private function assertNotRegistered(EntryResolverInterface $resolver): void
    {
        foreach ($this->resolvers as $registered) {
            if ($registered === $resolver) {
                throw new InvalidArgumentException(sprintf(
                    'Entry resolver %s is already registered.',
                    $resolver::class,
                ));
            }
        }
    }

    private function findOwner(string $id): ?EntryResolverInterface
    {
        if (isset($this->definitionOwners[$id])) {
            return $this->definitionOwners[$id];
        }

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

<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\DI\Definition\DefinitionInterface;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\NotFoundException;

/** Ordered entry resolver chain with owner caching. */
final class CompositeResolver implements DefinitionAwareResolverInterface
{
    /** @var list<EntryResolverInterface> */
    private array $resolvers;
    /** @var array<string, EntryResolverInterface|null> */
    private array $owners = [];
    /** @var array<string, DefinitionAwareResolverInterface> */
    private array $definitionOwners = [];

    public function __construct(EntryResolverInterface ...$resolvers)
    {
        $seen = [];
        foreach ($resolvers as $resolver) {
            $id = spl_object_id($resolver);
            if (isset($seen[$id])) {
                throw new InvalidConfigurationException(sprintf(
                    'Entry resolver %s is registered more than once.',
                    $resolver::class,
                ));
            }
            $seen[$id] = true;
        }
        $this->resolvers = array_values($resolvers);
    }

    public function can(string $id): bool
    {
        return $this->owner($id) !== null;
    }

    /** @param array<string|int, mixed> $params */
    public function resolve(string $id, array $params = []): mixed
    {
        $owner = $this->owner($id)
            ?? throw NotFoundException::forService($id);
        return $owner->resolve($id, $params);
    }

    public function setDefinition(string $id, DefinitionInterface $definition): void
    {
        foreach ($this->resolvers as $resolver) {
            if (!$resolver instanceof DefinitionAwareResolverInterface
                || !$resolver->supportsDefinition($definition)
            ) {
                continue;
            }
            $resolver->setDefinition($id, $definition);
            $this->definitionOwners[$id] = $resolver;
            $this->owners[$id] = $resolver;
            return;
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

    private function owner(string $id): ?EntryResolverInterface
    {
        if (isset($this->definitionOwners[$id])) {
            return $this->definitionOwners[$id];
        }
        if (array_key_exists($id, $this->owners)) {
            return $this->owners[$id];
        }
        foreach ($this->resolvers as $resolver) {
            if ($resolver->can($id)) {
                return $this->owners[$id] = $resolver;
            }
        }
        return $this->owners[$id] = null;
    }
}

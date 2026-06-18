<?php

declare(strict_types=1);

namespace Componenta\DI;

use IteratorAggregate;
use Psr\Container\ContainerInterface;
use Traversable;

/**
 * Holds the ordered list of PSR-11 containers the main {@see Container} will
 * delegate lookups to before consulting its own resolver chain.
 *
 * Registration is idempotent - re-registering the same container instance has
 * no effect - and preserves insertion order, which is also the lookup order.
 *
 * @internal
 *
 * @implements IteratorAggregate<int, ContainerInterface>
 */
final class ExternalContainerRegistry implements IteratorAggregate
{
    /** @var array<int, ContainerInterface> Indexed by spl_object_id for dedup. */
    private array $containers = [];

    public function register(ContainerInterface $container): void
    {
        $this->containers[spl_object_id($container)] ??= $container;
    }

    /**
     * Returns the first registered container that reports having the given id,
     * or null if none do.
     */
    public function findOwning(string $id): ?ContainerInterface
    {
        return array_find($this->containers, static fn($container) => $container->has($id));

    }

    public function has(string $id): bool
    {
        return $this->findOwning($id) !== null;
    }

    /**
     * Iterates over registered containers in insertion order.
     *
     * @return Traversable<int, ContainerInterface>
     */
    public function getIterator(): Traversable
    {
        yield from array_values($this->containers);
    }
}

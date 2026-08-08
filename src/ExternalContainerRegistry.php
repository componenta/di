<?php

declare(strict_types=1);

namespace Componenta\DI;

use Psr\Container\ContainerInterface;

/**
 * Holds the ordered list of PSR-11 containers the main {@see Container} will
 * delegate lookups to before consulting its own resolver chain.
 *
 * Registration is idempotent - re-registering the same container instance has
 * no effect - and preserves insertion order, which is also the lookup order.
 *
 * @internal
 */
final class ExternalContainerRegistry
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
        foreach ($this->containers as $container) {
            if ($container->has($id)) {
                return $container;
            }
        }

        return null;
    }
}

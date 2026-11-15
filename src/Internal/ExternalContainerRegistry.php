<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Psr\Container\ContainerInterface;

/**
 * Holds the ordered external PSR-11 containers used by the main container.
 *
 * @internal
 */
final class ExternalContainerRegistry
{
    /** @var array<int, ContainerInterface> */
    private array $containers = [];
    public private(set) int $revision = 0;

    public function register(ContainerInterface $container): void
    {
        $id = spl_object_id($container);
        if (!isset($this->containers[$id])) {
            $this->containers[$id] = $container;
            ++$this->revision;
        }
    }

    /**
     * Reentrant ownership callbacks can change membership between queries.
     * @phpstan-impure
     */
    public function contains(ContainerInterface $container): bool
    {
        return isset($this->containers[spl_object_id($container)]);
    }

    public function findOwning(string $id): ?ContainerInterface
    {
        $containers = $this->containers;
        $inspected = count($containers);
        while (true) {
            foreach ($containers as $container) {
                if ($container->has($id)) {
                    return $container;
                }
            }

            if ($inspected === count($this->containers)) {
                return null;
            }

            // Ownership callbacks may append providers, including while a Fiber
            // is suspended. Visit the new suffix without replaying earlier has().
            $containers = array_slice($this->containers, $inspected);
            $inspected += count($containers);
        }
    }
}

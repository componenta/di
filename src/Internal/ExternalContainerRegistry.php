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

    public function register(ContainerInterface $container): void
    {
        $this->containers[spl_object_id($container)] ??= $container;
    }

    public function contains(ContainerInterface $container): bool
    {
        return isset($this->containers[spl_object_id($container)]);
    }

    public function findOwning(string $id): ?ContainerInterface
    {
        $containers = $this->containers;
        $inspected = 0;
        while (true) {
            foreach ($containers as $container) {
                if ($container->has($id)) {
                    return $container;
                }
            }

            $inspected += count($containers);
            if ($inspected === count($this->containers)) {
                return null;
            }

            // Reentrant ownership callbacks may register providers during the lookup.
            $containers = array_slice($this->containers, $inspected, preserve_keys: true);
        }
    }
}

<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Psr\Container\ContainerInterface;

/**
 * Captures the entry IDs fetched while selecting one delegator chain.
 *
 * @internal
 */
final class CallableDependencyLookup implements ContainerInterface
{
    /** @var array<string, true> */
    public private(set) array $dependencies = [];

    public function __construct(private readonly ContainerInterface $container) {}

    public function has(string $id): bool
    {
        return AvailableEntryLookup::has($this->container, $id);
    }

    public function get(string $id): mixed
    {
        $this->dependencies[$id] = true;
        return AvailableEntryLookup::get($this->container, $id);
    }
}

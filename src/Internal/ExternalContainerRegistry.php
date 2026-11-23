<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Closure;
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
    public private(set) int $ownershipRevision = 0;
    /** @var array<string, int> */
    private array $ownershipChanges = [];

    public function register(ContainerInterface $container, ?object $authority = null): void
    {
        RuntimeCollaboratorOwnership::assertWritable($this, $authority);
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

    /** @param non-empty-list<string> $ids */
    public function noteOwnershipChanges(array $ids, ?object $authority = null): void
    {
        RuntimeCollaboratorOwnership::assertWritable($this, $authority);
        ++$this->ownershipRevision;
        foreach ($ids as $id) {
            $this->ownershipChanges[$id] = $this->ownershipRevision;
        }
    }

    public function ownershipRevisionFor(string $id): int
    {
        return $this->ownershipChanges[$id] ?? 0;
    }

    /** @param Closure(string):bool|null $mayInspect */
    public function findOwning(
        string $id,
        ?Closure $mayInspect = null,
        bool $requireResolvable = false,
        ?ExternalLookupProbes $lookupProbes = null,
    ): ?ContainerInterface {
        $probes = $lookupProbes?->start($id);
        $caller = $probes?->begin();
        try {
            $containers = $this->containers;
            $inspected = count($containers);
            while (true) {
                foreach ($containers as $providerId => $container) {
                    if ($mayInspect !== null && !$mayInspect($id)) {
                        return null;
                    }

                    $revision = $lookupProbes->revision ?? 0;
                    if ($probes !== null && !$probes->canProbe($providerId, $caller, $requireResolvable, $revision)) {
                        continue;
                    }
                    $probes?->enterProvider($providerId);
                    try {
                        if ($requireResolvable ? AvailableEntryLookup::has($container, $id) : $container->has($id)) {
                            $probes?->recordOwner($providerId);
                            return $container;
                        }
                        // A repeated recursive miss can reopen the same search tree.
                        if ($caller !== null) {
                            $probes?->recordRecursiveMiss($caller, $providerId, $requireResolvable, $revision);
                        }
                    } finally {
                        $probes?->leaveProvider($providerId);
                    }
                }

                if ($inspected === count($this->containers)) {
                    return null;
                }

                // Ownership callbacks may append providers, including while a Fiber
                // is suspended. Visit the new suffix without replaying earlier has().
                $containers = array_slice($this->containers, $inspected, preserve_keys: true);
                $inspected += count($containers);
            }
        } finally {
            if ($probes !== null) {
                $lookupProbes?->finish($id, $probes);
            }
        }
    }
}

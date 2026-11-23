<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Closure;
use Componenta\DI\Container;
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

    /** @param Closure(string):bool $mayInspect */
    public function findOwning(
        string $id,
        Closure $mayInspect,
        bool $requireResolvable,
        ExternalLookupProbes $lookupProbes,
        bool $forRegistration = false,
    ): ?ContainerInterface {
        $probes = $forRegistration ? null : $lookupProbes->start($id);
        $caller = $probes?->begin();
        $skipped = false;
        try {
            $containers = $this->containers;
            $inspected = count($containers);
            while (true) {
                foreach ($containers as $providerId => $container) {
                    $revision = $lookupProbes->revision;
                    if ($probes !== null && !$probes->canProbe($providerId, $caller, $requireResolvable, $revision)) {
                        $confirmed = $probes->confirmedOwnership($providerId, $lookupProbes->confirmationRevision, $revision);
                        if ($confirmed === null || (!$requireResolvable && $container instanceof Container)) {
                            $skipped = true;
                        } else {
                            if ($confirmed['registryRevision'] !== null) {
                                $lookupProbes->trackLookup();
                            }
                            if ($confirmed['owns']) {
                                return $mayInspect($id) ? $container : null;
                            }
                        }
                        continue;
                    }
                    if (!$mayInspect($id)) {
                        return null;
                    }
                    $probes?->enterProvider($providerId);
                    try {
                        $owns = $forRegistration
                            ? $lookupProbes->inspect($container, $id)
                            : ($requireResolvable ? AvailableEntryLookup::has($container, $id) : $container->has($id));
                        if ($owns) {
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
                $lookupProbes->finish($id, $probes, $skipped);
            }
        }
    }
}

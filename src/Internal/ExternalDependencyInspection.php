<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Closure;
use Psr\Container\ContainerInterface;

/**
 * Finds deferred dependencies whose owner would change when a provider is added.
 *
 * @internal
 */
final readonly class ExternalDependencyInspection
{
    /**
     * @param Closure():?ExternalContainerRegistry $registry Reads the current registry after reentrant callbacks.
     * @param Closure(string):bool $mayInspect Checks current protection before consulting providers.
     */
    public function __construct(
        private DelegatorRegistry $delegators,
        private Closure $registry,
        private Closure $mayInspect,
    ) {}

    /** @return list<string> */
    public function takenOverBy(ContainerInterface $container): array
    {
        $mayInspect = $this->mayInspect(...);
        $candidates = [];
        $inspected = [];
        $pending = [];
        do {
            $ownershipRevision = $this->currentRegistry()->ownershipRevision ?? 0;
            foreach ($this->delegators->deferredDependencies() as $dependencyId) {
                $dependencyRevision = $this->currentRegistry()?->ownershipRevisionFor($dependencyId) ?? 0;
                $previousRevision = $inspected[$dependencyId] ?? null;
                if ($previousRevision === $dependencyRevision && !isset($pending[$dependencyId])) {
                    continue;
                }
                if ($previousRevision !== null && $previousRevision !== $dependencyRevision) {
                    unset($candidates[$dependencyId]);
                }
                $inspected[$dependencyId] = $dependencyRevision;
                if (!$this->mayInspect($dependencyId)) {
                    $pending[$dependencyId] = true;
                    continue;
                }
                unset($pending[$dependencyId]);
                do {
                    $revision = $this->currentRegistry()->revision ?? 0;
                    $owner = $this->currentRegistry()?->findOwning($dependencyId, $mayInspect);
                    if ($this->currentRegistry()?->contains($container) === true) {
                        return [];
                    }
                } while ($revision !== ($this->currentRegistry()->revision ?? 0)
                    && $this->mayInspect($dependencyId));
                if (!$this->mayInspect($dependencyId)) {
                    $pending[$dependencyId] = true;
                    continue;
                }
                if ($owner === null && $container->has($dependencyId)) {
                    $candidates[$dependencyId] = true;
                }
                if ($this->currentRegistry()?->contains($container) === true) {
                    return [];
                }
            }

            // Keep candidate answers, but prove ownership against the completed
            // registry. Existing-owner callbacks can publish further containers.
            $dependencies = [];
            $protectedCandidates = [];
            $revision = $this->currentRegistry()->revision ?? 0;
            foreach ($candidates as $id => $_) {
                $dependencyId = (string) $id;
                if (!$this->delegators->dependsOn($dependencyId)) {
                    continue;
                }
                if (!$this->mayInspect($dependencyId)) {
                    $protectedCandidates[$dependencyId] = true;
                    continue;
                }
                $owner = $this->currentRegistry()?->findOwning($dependencyId, $mayInspect);
                if ($this->currentRegistry()?->contains($container) === true) {
                    return [];
                }
                if (!$this->mayInspect($dependencyId)) {
                    $protectedCandidates[$dependencyId] = true;
                    continue;
                }
                if ($owner === null) {
                    $dependencies[] = $dependencyId;
                }
            }
            // A later callback can make a skipped alias externally eligible.
            // Revisit only its unfinished inspection/proof, never a candidate answer.
            $retryProtected = false;
            foreach ($pending + $protectedCandidates as $dependencyId => $_) {
                if ($this->mayInspect((string) $dependencyId)) {
                    $retryProtected = true;
                    break;
                }
            }
            // Either phase can also introduce new deferred dependency IDs.
        } while ($retryProtected
            || $revision !== ($this->currentRegistry()->revision ?? 0)
            || $ownershipRevision !== ($this->currentRegistry()->ownershipRevision ?? 0)
            || array_diff($this->delegators->deferredDependencies(), array_keys($inspected)) !== []);

        // A later proof may also protect a dependency already selected above.
        return array_values(array_filter($dependencies, $mayInspect));
    }

    /** @phpstan-impure */
    private function currentRegistry(): ?ExternalContainerRegistry
    {
        return ($this->registry)();
    }

    /** @phpstan-impure */
    private function mayInspect(string $id): bool
    {
        return ($this->mayInspect)($id);
    }
}

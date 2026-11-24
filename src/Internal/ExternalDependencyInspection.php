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
        private ExternalLookupProbes $lookupProbes,
    ) {}

    /** @return list<string> */
    public function takenOverBy(ContainerInterface $container): array
    {
        return $this->lookupProbes->duringRegistration(fn(): array => $this->inspectDependencies($container));
    }

    /** @return list<string> */
    private function inspectDependencies(ContainerInterface $container): array
    {
        $mayInspect = $this->mayInspect(...);
        $candidates = [];
        $inspected = [];
        $membershipEvidence = [];
        $reads = [];
        $pending = [];
        do {
            $ownershipRevision = $this->currentRegistry()->ownershipRevision ?? 0;
            foreach ($this->delegators->deferredDependencies() as $dependencyId) {
                $membershipRevision = $this->lookupProbes->membershipRevision;
                $dependencyRevision = $this->currentRegistry()?->ownershipRevisionFor($dependencyId) ?? 0;
                if (($inspected[$dependencyId] ?? null) === $dependencyRevision && !isset($pending[$dependencyId])
                    && ($membershipEvidence[$dependencyId] ?? $membershipRevision) >= $membershipRevision
                    && $this->lookupProbes->readsStillCurrent($reads[$dependencyId] ?? [])
                ) {
                    continue;
                }
                unset($candidates[$dependencyId], $membershipEvidence[$dependencyId], $reads[$dependencyId]);
                $inspected[$dependencyId] = $dependencyRevision;
                if (!$this->mayInspect($dependencyId)) {
                    $pending[$dependencyId] = true;
                    continue;
                }
                unset($pending[$dependencyId]);
                do {
                    $registry = $this->currentRegistry();
                    $revision = $registry->revision ?? 0;
                    $ownership = $this->inspectExisting($registry, $dependencyId, $mayInspect);
                    if ($this->currentRegistry()?->contains($container) === true) {
                        return [];
                    }
                } while ($revision !== ($this->currentRegistry()->revision ?? 0)
                    && $this->mayInspect($dependencyId));
                if (!$this->mayInspect($dependencyId)) {
                    $pending[$dependencyId] = true;
                    continue;
                }
                $reads[$dependencyId] = $ownership['reads'];
                if ($ownership['result'] !== null) {
                    // A later append can change the owner that blocked candidate inspection.
                    $membershipEvidence[$dependencyId] = $this->lookupProbes->membershipRevision;
                } else {
                    $answer = $this->lookupProbes->observeLookup(static fn(): bool => AvailableEntryLookup::has($container, $dependencyId));
                    if ($this->mayInspect($dependencyId)) {
                        // The completed proof below handles later changes to this ID's protection.
                        $reads[$dependencyId] += $answer['reads'];
                    }
                    $answerRevision = $answer['registryRevision'];
                    if (!$answer['result'] || ($answerRevision !== null && $answerRevision < $this->lookupProbes->membershipRevision)) {
                        // An already superseded candidate answer is stale in either direction.
                        $membershipEvidence[$dependencyId] = $answerRevision;
                    }
                    if ($answer['result']) {
                        $candidates[$dependencyId] = true;
                    }
                }
                if ($this->currentRegistry()?->contains($container) === true) {
                    return [];
                }
            }

            // Prove ownership against the completed registry. Existing-owner
            // callbacks can publish further containers.
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
                $ownership = $this->inspectExisting($this->currentRegistry(), $dependencyId, $mayInspect);
                $reads[$dependencyId] += $ownership['reads'];
                if ($this->currentRegistry()?->contains($container) === true) {
                    return [];
                }
                if (!$this->mayInspect($dependencyId)) {
                    $protectedCandidates[$dependencyId] = true;
                    continue;
                }
                if ($ownership['result'] === null) {
                    $dependencies[] = $dependencyId;
                }
            }
            // A later callback can make a skipped alias externally eligible.
            $retryProtected = false;
            foreach ($pending + $protectedCandidates as $dependencyId => $_) {
                if ($this->mayInspect((string) $dependencyId)) {
                    $retryProtected = true;
                    break;
                }
            }
            // Either phase can add dependencies or invalidate evidence from an earlier check.
            $registry = $this->currentRegistry();
            $registryRevision = $registry->revision ?? 0;
            $membershipRevision = $this->lookupProbes->membershipRevision;
        } while ($retryProtected
            || $revision !== $registryRevision
            || $ownershipRevision !== ($registry->ownershipRevision ?? 0)
            || array_any(
                $this->delegators->deferredDependencies(),
                fn(string $id): bool => !isset($inspected[$id])
                    || ($membershipEvidence[$id] ?? $membershipRevision) < $membershipRevision
                    || !$this->lookupProbes->readsStillCurrent($reads[$id] ?? []),
            ));

        // A later proof may also protect a dependency already selected above.
        return array_values(array_filter($dependencies, $mayInspect));
    }

    /**
     * @param Closure(string):bool $mayInspect
     * @return array{result:?ContainerInterface,reads:array<string,int>}
     */
    private function inspectExisting(?ExternalContainerRegistry $registry, string $id, Closure $mayInspect): array
    {
        if ($registry === null) {
            return ['result' => null, 'reads' => []];
        }

        return $this->lookupProbes->observeLookup(fn(): ?ContainerInterface => $registry->findOwning(
            $id,
            $mayInspect,
            requireResolvable: true,
            lookupProbes: $this->lookupProbes,
            forRegistration: true,
        ));
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

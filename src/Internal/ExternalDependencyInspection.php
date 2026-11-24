<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Closure;
use Componenta\DI\Container;
use Psr\Container\ContainerInterface;

/**
 * Finds deferred dependencies and active reads whose owner would change when a provider is added.
 *
 * @phpstan-import-type LookupReads from ExternalLookupProbes
 * @phpstan-import-type LookupIds from ExternalLookupProbes
 * @phpstan-type AffectedDependencies array{delegators:list<string>,lookups:LookupIds}
 * @phpstan-type ProbeAnswer array{result:bool,registryRevision:?int,reads:LookupReads}
 * @phpstan-type ProbeMemo array<array-key,array<int,array{requireResolvable:bool,membership:int,ownership:int,answer:ProbeAnswer}>>
 * @internal
 */
final readonly class ExternalDependencyInspection
{
    private const array NONE_AFFECTED = ['delegators' => [], 'lookups' => ExternalLookupProbes::EMPTY_IDS];

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

    /** @return AffectedDependencies */
    public function takenOverBy(ContainerInterface $container): array
    {
        // Reads performed by the ownership callbacks below do not extend this registration.
        $activeReads = $this->lookupProbes->pendingRetrievalDependencies();
        return $this->lookupProbes->duringRegistration(fn(): array => $this->inspectDependencies($container, $activeReads));
    }

    /**
     * @param LookupIds $activeReads
     * @return AffectedDependencies
     */
    private function inspectDependencies(ContainerInterface $container, array $activeReads): array
    {
        $mayInspect = $this->mayInspect(...);
        $candidates = [];
        $inspected = [];
        $membershipEvidence = [];
        $reads = [];
        $pending = [];
        do {
            $ownershipRevision = $this->currentRegistry()->ownershipRevision ?? 0;
            $targets = $this->targets($activeReads);
            $initialProbes = [];
            foreach ($targets as $key => [$id, $requireResolvable]) {
                $bothModes = isset($targets['r:' . $id], $targets['p:' . $id]);
                $membershipRevision = $this->lookupProbes->membershipRevision;
                $dependencyRevision = $this->currentRegistry()?->ownershipRevisionFor($id) ?? 0;
                if (($inspected[$key] ?? null) === $dependencyRevision && !isset($pending[$key])
                    && ($membershipEvidence[$key] ?? $membershipRevision) >= $membershipRevision
                    && $this->lookupProbes->readsStillCurrent($reads[$key] ?? ExternalLookupProbes::EMPTY_READS)
                ) {
                    continue;
                }
                unset($candidates[$key], $membershipEvidence[$key], $reads[$key]);
                $inspected[$key] = $dependencyRevision;
                if (!$this->mayInspect($id)) {
                    $pending[$key] = $id;
                    continue;
                }
                unset($pending[$key]);
                do {
                    $registry = $this->currentRegistry();
                    $revision = $registry->revision ?? 0;
                    $ownership = $this->inspectExisting($registry, $id, $mayInspect, $requireResolvable, $bothModes, $initialProbes);
                    if ($this->currentRegistry()?->contains($container) === true) {
                        return self::NONE_AFFECTED;
                    }
                } while ($revision !== ($this->currentRegistry()->revision ?? 0)
                    && $this->mayInspect($id));
                if (!$this->mayInspect($id)) {
                    $pending[$key] = $id;
                    continue;
                }
                $reads[$key] = $ownership['reads'];
                if ($ownership['result'] !== null) {
                    // A later append can change the owner that blocked candidate inspection.
                    $membershipEvidence[$key] = $this->lookupProbes->membershipRevision;
                } else {
                    $answer = $this->inspectProvider(
                        $container,
                        $id,
                        $requireResolvable,
                        $bothModes,
                        $initialProbes,
                        static fn(): bool => $requireResolvable ? AvailableEntryLookup::has($container, $id) : $container->has($id),
                    );
                    if ($this->mayInspect($id)) {
                        $reads[$key] = self::mergeReads($reads[$key], $answer['reads']);
                    } elseif (!$answer['result']) {
                        // A miss while protected cannot settle the public ownership after reopening.
                        $pending[$key] = $id;
                    }
                    $answerRevision = $answer['registryRevision'];
                    if (!$answer['result'] || ($answerRevision !== null && $answerRevision < $this->lookupProbes->membershipRevision)) {
                        // An already superseded candidate answer is stale in either direction.
                        $membershipEvidence[$key] = $answerRevision;
                    }
                    if ($answer['result']) {
                        $candidates[$key] = [$id, $requireResolvable];
                    }
                }
                if ($this->currentRegistry()?->contains($container) === true) {
                    return self::NONE_AFFECTED;
                }
            }

            // Prove ownership against the completed registry. Existing-owner
            // callbacks can publish further containers.
            $dependencies = self::NONE_AFFECTED;
            $protectedCandidates = [];
            $finalProbes = [];
            $revision = $this->currentRegistry()->revision ?? 0;
            foreach ($candidates as $key => [$id, $requireResolvable]) {
                if ($requireResolvable && !$this->delegators->dependsOn($id) && !isset($activeReads['resolvable'][$id])) {
                    continue;
                }
                if (!$this->mayInspect($id)) {
                    $protectedCandidates[$key] = $id;
                    continue;
                }
                $bothModes = isset($targets['r:' . $id], $targets['p:' . $id]);
                $ownership = $this->inspectExisting($this->currentRegistry(), $id, $mayInspect, $requireResolvable, $bothModes, $finalProbes);
                $reads[$key] = self::mergeReads($reads[$key], $ownership['reads']);
                if ($this->currentRegistry()?->contains($container) === true) {
                    return self::NONE_AFFECTED;
                }
                if (!$this->mayInspect($id)) {
                    $protectedCandidates[$key] = $id;
                    continue;
                }
                if ($ownership['result'] === null) {
                    $mode = $requireResolvable ? 'resolvable' : 'psr';
                    $dependencies['lookups'][$mode][$id] = true;
                    if ($requireResolvable && $this->delegators->dependsOn($id)) {
                        $dependencies['delegators'][] = $id;
                    }
                }
            }
            // A later callback can make a skipped alias externally eligible.
            $retryProtected = false;
            foreach ($pending + $protectedCandidates as $id) {
                if ($this->mayInspect($id)) {
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
                $this->targets($activeReads),
                fn(array $_, string $key): bool => !isset($inspected[$key])
                    || ($membershipEvidence[$key] ?? $membershipRevision) < $membershipRevision
                    || !$this->lookupProbes->readsStillCurrent($reads[$key] ?? ExternalLookupProbes::EMPTY_READS),
            ));

        // A later proof may also protect a dependency already selected above.
        $dependencies['delegators'] = array_values(array_filter($dependencies['delegators'], $mayInspect));
        foreach ($dependencies['lookups'] as $mode => $ids) {
            $dependencies['lookups'][$mode] = array_filter($ids, static fn(int|string $id): bool => $mayInspect((string) $id), ARRAY_FILTER_USE_KEY);
        }
        return $dependencies;
    }

    /**
     * @param LookupIds $activeReads
     * @return array<string,array{string,bool}>
     */
    private function targets(array $activeReads): array
    {
        $targets = [];
        foreach ($this->delegators->deferredDependencies() as $id) {
            $targets['r:' . $id] = [$id, true];
        }
        foreach ($activeReads['resolvable'] as $id => $_) {
            $targets['r:' . $id] = [(string) $id, true];
        }
        foreach ($activeReads['psr'] as $id => $_) {
            $targets['p:' . $id] = [(string) $id, false];
        }
        return $targets;
    }

    /**
     * @param Closure(string):bool $mayInspect
     * @param ProbeMemo $probes
     * @return array{result:?ContainerInterface,reads:LookupReads}
     */
    private function inspectExisting(
        ?ExternalContainerRegistry $registry,
        string $id,
        Closure $mayInspect,
        bool $requireResolvable,
        bool $bothModes,
        array &$probes,
    ): array {
        if ($registry === null) {
            return ['result' => null, 'reads' => ExternalLookupProbes::EMPTY_READS];
        }

        $providerReads = ExternalLookupProbes::EMPTY_READS;
        $probe = function (ContainerInterface $provider) use ($id, $requireResolvable, $bothModes, &$probes, &$providerReads): bool {
            if (!$bothModes || $provider instanceof Container) {
                return $this->lookupProbes->inspect($provider, $id, $requireResolvable);
            }
            $answer = $this->inspectProvider(
                $provider,
                $id,
                $requireResolvable,
                true,
                $probes,
                fn(): bool => $this->lookupProbes->inspect($provider, $id, $requireResolvable),
            );
            $providerReads = self::mergeReads($providerReads, $answer['reads']);
            return $answer['result'];
        };
        $ownership = $this->lookupProbes->observeLookup(fn(): ?ContainerInterface => $registry->findOwning(
            $id,
            $mayInspect,
            $requireResolvable,
            lookupProbes: $this->lookupProbes,
            registrationProbe: $probe,
        ));
        return ['result' => $ownership['result'], 'reads' => self::mergeReads($providerReads, $ownership['reads'])];
    }

    /**
     * An ordinary PSR-11 provider uses one has() implementation for both modes.
     *
     * @param ProbeMemo $probes
     * @param Closure():bool $check
     * @return ProbeAnswer
     */
    private function inspectProvider(
        ContainerInterface $provider,
        string $id,
        bool $requireResolvable,
        bool $bothModes,
        array &$probes,
        Closure $check,
    ): array {
        if (!$bothModes || $provider instanceof Container) {
            return $this->lookupProbes->observeLookup($check);
        }

        $providerId = spl_object_id($provider);
        $membership = $this->lookupProbes->membershipRevision;
        $ownership = $this->currentRegistry()?->ownershipRevisionFor($id) ?? 0;
        $previous = $probes[$id][$providerId] ?? null;
        if ($previous !== null && $previous['requireResolvable'] !== $requireResolvable
            && $previous['membership'] === $membership && $previous['ownership'] === $ownership
            && $this->lookupProbes->readsStillCurrent($previous['answer']['reads'])
        ) {
            return $previous['answer'];
        }

        $answer = $this->lookupProbes->observeLookup($check);
        $probes[$id][$providerId] = [
            'requireResolvable' => $requireResolvable,
            'membership' => $membership,
            'ownership' => $ownership,
            'answer' => $answer,
        ];
        return $answer;
    }

    /**
     * @param LookupReads $earlier
     * @param LookupReads $later
     * @return LookupReads
     */
    private static function mergeReads(array $earlier, array $later): array
    {
        foreach ($earlier as $mode => $byKind) {
            foreach ($byKind as $kind => $revisions) {
                $earlier[$mode][$kind] = $revisions + $later[$mode][$kind];
            }
        }
        return $earlier;
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

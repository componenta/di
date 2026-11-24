<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Closure;
use Componenta\DI\Container;
use Fiber;
use Psr\Container\ContainerInterface;
use WeakMap;

/**
 * Owns the active external probes for each lookup execution and entry ID.
 *
 * A scope starts with just its ID. Nested reads and matching notifications
 * extend it only while the operation is in flight.
 *
 * @phpstan-type ReadRevisions array{value:array<string,int>,availability:array<string,int>}
 * @phpstan-type LookupReads array{psr:ReadRevisions,resolvable:ReadRevisions}
 * @phpstan-type LookupIds array{psr:array<array-key,true>,resolvable:array<array-key,true>}
 * @phpstan-type ObservedLookup array{id:?string,registryRevision:?int,reads:LookupReads,superseded:bool,retrieval:bool}
 * @phpstan-type LookupObservation string|ObservedLookup|null
 * @internal
 */
final class ExternalLookupProbes
{
    private const array EMPTY_REVISIONS = ['value' => [], 'availability' => []];
    public const array EMPTY_READS = ['psr' => self::EMPTY_REVISIONS, 'resolvable' => self::EMPTY_REVISIONS];
    public const array EMPTY_IDS = ['psr' => [], 'resolvable' => []];

    /** @var array<string,ExternalProbeState> */
    private array $main = [];
    /** @var WeakMap<object,array<string,ExternalProbeState>> */
    private readonly WeakMap $fibers;
    /** @var list<LookupObservation> */
    private array $mainObservations = [];
    /** @var WeakMap<object,list<LookupObservation>> */
    private readonly WeakMap $fiberObservations;
    /** @var array<int,Closure(non-empty-list<string>):void> */
    private array $ownershipObservers = [];
    /** @var LookupReads Mutation revisions for IDs read by live registrations. */
    private array $registrationReads = self::EMPTY_READS;
    /** Local changes and provider appends can invalidate any nested registry read. */
    private int $lastUnscopedChange = 0;
    public private(set) int $revision = 0;
    public private(set) int $confirmationRevision = 0;
    public private(set) int $membershipRevision = 0;

    public function __construct(
        private readonly AliasResolver $aliases,
        private readonly DelegatorRegistry $delegators,
    ) {
        $this->fibers = new WeakMap();
        $this->fiberObservations = new WeakMap();
    }

    public function start(string $id): ExternalProbeState
    {
        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            $state = $this->main[$id] ??= new ExternalProbeState();
            $this->mainObservations[] = $id;
            return $state;
        }

        $state = $this->fiberState($id, $fiber);
        $this->pushFiberObservation($fiber, $id);
        return $state;
    }

    public function finish(string $id, ExternalProbeState $state, bool $skipped): void
    {
        $fiber = Fiber::getCurrent();
        $observation = $fiber === null ? array_pop($this->mainObservations) : $this->popFiberObservation($fiber);
        if ($state->finish()) {
            $this->release($id, $fiber);
        }
        if ($skipped || (is_array($observation) && ($observation['superseded']
            || ($observation['registryRevision'] !== null && $observation['registryRevision'] < $this->lastUnscopedChange)))
        ) {
            // The selected route can finish. A skipped or superseded ownership
            // answer cannot supply a result to a shared cache.
            EntryLookupRevision::markIncomplete();
        }
    }

    public function retrieve(ContainerInterface $container, string $id, bool $requireResolvable): mixed
    {
        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            $this->mainObservations[] = self::observation($id, retrieval: true);
        } else {
            $this->pushFiberObservation($fiber, self::observation($id, retrieval: true));
        }
        try {
            return $requireResolvable ? AvailableEntryLookup::get($container, $id) : $container->get($id);
        } finally {
            $observation = $fiber === null ? array_pop($this->mainObservations) : $this->popFiberObservation($fiber);
            if (is_array($observation) && $observation['superseded']) {
                EntryLookupRevision::markIncomplete();
            }
        }
    }

    public function inspect(ContainerInterface $container, string $id, bool $requireResolvable): bool
    {
        $fiber = Fiber::getCurrent();
        $state = $fiber === null ? ($this->main[$id] ??= new ExternalProbeState()) : $this->fiberState($id, $fiber);
        $confirmationRevision = $this->confirmationRevision;
        try {
            return $state->inspect(
                spl_object_id($container),
                $requireResolvable || !$container instanceof Container,
                function () use ($container, $id, $requireResolvable, $confirmationRevision): array {
                    $ownership = $this->observeLookup(static fn(): bool => $requireResolvable
                        ? AvailableEntryLookup::has($container, $id)
                        : $container->has($id));
                    return [
                        'owns' => $ownership['result'],
                        'revision' => $confirmationRevision,
                        'registryRevision' => $ownership['registryRevision'],
                    ];
                },
            );
        } finally {
            if ($state->isIdle) {
                $this->release($id, $fiber);
            }
        }
    }

    /**
     * Records the revision of the first registry read in this operation.
     *
     * @template T
     * @param Closure():T $operation
     * @return array{result:T,registryRevision:?int,reads:LookupReads}
     */
    public function observeLookup(Closure $operation): array
    {
        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            $this->mainObservations[] = null;
        } else {
            $this->pushFiberObservation($fiber, null);
        }
        try {
            $result = $operation();
        } finally {
            $observation = $fiber === null ? array_pop($this->mainObservations) : $this->popFiberObservation($fiber);
        }
        return [
            'result' => $result,
            'registryRevision' => $observation['registryRevision'] ?? null,
            'reads' => $observation['reads'] ?? self::EMPTY_READS,
        ];
    }

    /**
     * @template T
     * @param Closure():T $operation
     * @param Closure(non-empty-list<string>):void $ownershipChanged
     * @return T
     */
    public function duringRegistration(Closure $operation, Closure $ownershipChanged): mixed
    {
        $observerId = spl_object_id($ownershipChanged);
        $this->ownershipObservers[$observerId] = $ownershipChanged;
        try {
            return $operation();
        } finally {
            unset($this->ownershipObservers[$observerId]);
            if ($this->ownershipObservers === []) {
                $this->registrationReads = self::EMPTY_READS;
            }
        }
    }

    /** @param LookupReads $reads */
    public function readsStillCurrent(array $reads): bool
    {
        foreach ($reads as $mode => $byKind) {
            foreach ($byKind as $kind => $revisions) {
                foreach ($revisions as $id => $revision) {
                    if (($this->registrationReads[$mode][$kind][$id] ?? 0) > $revision) {
                        return false;
                    }
                }
            }
        }
        return true;
    }

    public function observesReads(): bool
    {
        return $this->registrationReads !== self::EMPTY_READS
            || $this->mainObservations !== []
            || count($this->fiberObservations) !== 0;
    }

    public function trackLookup(string $id, bool $availability = false, bool $requireResolvable = false): void
    {
        if ($this->mainObservations === [] && count($this->fiberObservations) === 0) {
            return;
        }
        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            $this->recordLookup($this->mainObservations, $id, $availability, $requireResolvable);
        } elseif (isset($this->fiberObservations[$fiber])) {
            $this->recordLookup($this->fiberObservations[$fiber], $id, $availability, $requireResolvable);
        }
    }

    /** @param list<LookupObservation> $observations */
    private function recordLookup(array &$observations, string $id, bool $availability, bool $requireResolvable): void
    {
        // A nested read also supplies evidence to the enclosing observers.
        $kind = $availability ? 'availability' : 'value';
        $mode = $requireResolvable ? 'resolvable' : 'psr';
        foreach ($observations as &$observation) {
            if (!is_array($observation)) {
                $observation = self::observation($observation);
            }
            $observation['registryRevision'] ??= $this->revision;
            $observation['reads'][$mode][$kind][$id] ??= $this->revision;
            if ($observation['id'] === null && $this->ownershipObservers !== []) {
                $this->registrationReads[$mode][$kind][$id] ??= 0;
            }
        }
    }

    /** @return ObservedLookup */
    private static function observation(?string $id, bool $retrieval = false, bool $superseded = false): array
    {
        return [
            'id' => $id,
            'registryRevision' => null,
            'reads' => self::EMPTY_READS,
            'superseded' => $superseded,
            'retrieval' => $retrieval,
        ];
    }

    /**
     * @param Fiber<mixed,mixed,mixed,mixed> $fiber
     * @param LookupObservation $observation
     */
    private function pushFiberObservation(Fiber $fiber, string|array|null $observation): void
    {
        $this->fiberObservations[$fiber] ??= [];
        $this->fiberObservations[$fiber][] = $observation;
    }

    /**
     * @param Fiber<mixed,mixed,mixed,mixed> $fiber
     * @return LookupObservation
     */
    private function popFiberObservation(Fiber $fiber): string|array|null
    {
        $observation = array_pop($this->fiberObservations[$fiber]);
        if ($this->fiberObservations[$fiber] === []) {
            unset($this->fiberObservations[$fiber]);
        }
        return $observation;
    }

    /** @param Fiber<mixed,mixed,mixed,mixed> $fiber */
    private function fiberState(string $id, Fiber $fiber): ExternalProbeState
    {
        $states = $this->fibers[$fiber] ?? [];
        $state = $states[$id] ??= new ExternalProbeState();
        $this->fibers[$fiber] = $states;
        return $state;
    }

    /** @param Fiber<mixed,mixed,mixed,mixed>|null $fiber */
    private function release(string $id, ?Fiber $fiber): void
    {
        if ($fiber === null) {
            unset($this->main[$id]);
            return;
        }

        $states = $this->fibers[$fiber];
        unset($states[$id]);
        if ($states === []) {
            unset($this->fibers[$fiber]);
            return;
        }
        $this->fibers[$fiber] = $states;
    }

    /**
     * @param list<string> $invalidated
     * @param array<array-key,int|true> $directChanges
     * @param LookupIds $ownerChanges
     */
    public function invalidate(
        bool $appending = false,
        array $invalidated = [],
        array $directChanges = [],
        ?string $canonicalBinding = null,
        bool $availabilityChanged = true,
        bool $baseValueChanged = true,
        array $ownerChanges = self::EMPTY_IDS,
    ): void {
        $this->lastUnscopedChange = ++$this->revision;
        if ($appending) {
            $this->membershipRevision = $this->revision;
        } else {
            $this->confirmationRevision = $this->revision;
        }
        if ($this->observesReads()) {
            $this->recordChangedReads($invalidated, $directChanges, $canonicalBinding, $availabilityChanged, $baseValueChanged, $ownerChanges);
        }
    }

    /** @param list<string> $invalidated */
    public function noteInvalidatedEntries(array $invalidated): void
    {
        if ($this->observesReads()) {
            $this->recordChangedReads($invalidated);
        }
    }

    /**
     * @param list<string> $invalidated
     * @param array<array-key,int|true> $directChanges
     * @param LookupIds $ownerChanges
     */
    private function recordChangedReads(
        array $invalidated,
        array $directChanges = [],
        ?string $canonicalBinding = null,
        bool $availabilityChanged = true,
        bool $baseValueChanged = true,
        array $ownerChanges = self::EMPTY_IDS,
    ): void {
        $results = array_fill_keys($invalidated, true);
        $changed = fn(string $id, bool $availability, string $mode): bool => isset($directChanges[$id])
            || isset($ownerChanges[$mode][$id])
            || (!$availability && isset($results[$id]))
            || ($canonicalBinding !== null
                && ($availability ? $availabilityChanged : ($baseValueChanged || $this->delegators->hasPipeline($id)))
                && $this->aliases->resolve($id) === $canonicalBinding);
        foreach ($this->registrationReads as $mode => $byKind) {
            foreach ($byKind as $kind => $reads) {
                foreach ($reads as $id => $_) {
                    if ($changed((string) $id, $kind === 'availability', $mode)) {
                        $this->registrationReads[$mode][$kind][$id] = $this->revision;
                    }
                }
            }
        }
        self::invalidateRetrievalReads($this->mainObservations, $changed);
        foreach ($this->fiberObservations as $fiber => $_) {
            self::invalidateRetrievalReads($this->fiberObservations[$fiber], $changed);
        }
    }

    /**
     * @param list<LookupObservation> $observations
     * @param Closure(string,bool,'psr'|'resolvable'):bool $changed
     */
    private static function invalidateRetrievalReads(array &$observations, Closure $changed): void
    {
        foreach ($observations as &$observation) {
            if (is_array($observation) && $observation['retrieval'] && !$observation['superseded']
                && self::readWasChanged($observation['reads'], $changed)
            ) {
                $observation['superseded'] = true;
            }
        }
    }

    /**
     * @param LookupReads $reads
     * @param Closure(string,bool,'psr'|'resolvable'):bool $changed
     */
    private static function readWasChanged(array $reads, Closure $changed): bool
    {
        foreach ($reads as $mode => $byKind) {
            foreach ($byKind as $kind => $revisions) {
                foreach ($revisions as $id => $_) {
                    if ($changed((string) $id, $kind === 'availability', $mode)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /** @return LookupIds */
    public function pendingRetrievalDependencies(): array
    {
        $ids = self::EMPTY_IDS;
        self::recordPendingReadIds($this->mainObservations, $ids);
        foreach ($this->fiberObservations as $observations) {
            self::recordPendingReadIds($observations, $ids);
        }
        return $ids;
    }

    /**
     * @param list<LookupObservation> $observations
     * @param LookupIds $ids
     */
    private static function recordPendingReadIds(array $observations, array &$ids): void
    {
        foreach ($observations as $observation) {
            if (is_array($observation) && $observation['retrieval'] && !$observation['superseded']) {
                foreach ($observation['reads'] as $mode => $byKind) {
                    foreach ($byKind as $revisions) {
                        foreach ($revisions as $id => $_) {
                            $ids[$mode][$id] = true;
                        }
                    }
                }
            }
        }
    }

    /**
     * @param list<string> $ids
     * @param list<string> $dependentIds
     */
    public function notify(array $ids, array $dependentIds): void
    {
        $this->confirmationRevision = ++$this->revision;
        if ($dependentIds !== []) {
            foreach ($this->ownershipObservers as $ownershipChanged) {
                $ownershipChanged($dependentIds);
            }
        }
        if (!$this->observesReads()) {
            return;
        }
        $notifiedIds = array_fill_keys($ids, true);
        $this->recordChangedReads([], $notifiedIds);
        $this->notifyLookups($this->mainObservations, $notifiedIds);
        foreach ($this->fiberObservations as $fiber => $_) {
            $this->notifyLookups($this->fiberObservations[$fiber], $notifiedIds);
        }
    }

    /**
     * @param list<LookupObservation> $observations
     * @param array<string, true> $ids
     */
    private function notifyLookups(array &$observations, array $ids): void
    {
        foreach ($observations as &$observation) {
            if (is_array($observation)) {
                $id = $observation['id'];
                if (($id !== null && isset($ids[$id]))
                    || self::readWasChanged($observation['reads'], static fn(string $readId): bool => isset($ids[$readId]))
                ) {
                    $observation['superseded'] = true;
                }
            } elseif ($observation !== null && isset($ids[$observation])) {
                $observation = self::observation($observation, superseded: true);
            }
        }
    }
}

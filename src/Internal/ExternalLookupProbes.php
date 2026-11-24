<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Closure;
use Fiber;
use Psr\Container\ContainerInterface;
use WeakMap;

/**
 * Owns the active external probes for each lookup execution and entry ID.
 *
 * A scope starts with just its ID. Nested reads and matching notifications
 * extend it only while the operation is in flight.
 *
 * @phpstan-type ObservedLookup array{id:?string,registryRevision:?int,reads:array<string,int>,notified:bool}
 * @phpstan-type LookupObservation string|ObservedLookup|null
 * @internal
 */
final class ExternalLookupProbes
{
    /** @var array<string,ExternalProbeState> */
    private array $main = [];
    /** @var WeakMap<object,array<string,ExternalProbeState>> */
    private readonly WeakMap $fibers;
    /** @var list<LookupObservation> */
    private array $mainObservations = [];
    /** @var WeakMap<object,list<LookupObservation>> */
    private readonly WeakMap $fiberObservations;
    private int $activeRegistrations = 0;
    /** @var array<string,int> Mutation revisions for IDs read by live registrations. */
    private array $registrationReads = [];
    /** Local changes and provider appends can invalidate any nested registry read. */
    private int $lastUnscopedChange = 0;
    public private(set) int $revision = 0;
    public private(set) int $confirmationRevision = 0;
    public private(set) int $membershipRevision = 0;

    public function __construct(private readonly AliasResolver $aliases)
    {
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
        if ($skipped || (is_array($observation) && ($observation['notified']
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
            $this->mainObservations[] = $id;
        } else {
            $this->pushFiberObservation($fiber, $id);
        }
        try {
            return $requireResolvable ? AvailableEntryLookup::get($container, $id) : $container->get($id);
        } finally {
            $observation = $fiber === null ? array_pop($this->mainObservations) : $this->popFiberObservation($fiber);
            if (is_array($observation) && $observation['notified']) {
                EntryLookupRevision::markIncomplete();
            }
        }
    }

    public function inspect(ContainerInterface $container, string $id): bool
    {
        $fiber = Fiber::getCurrent();
        $state = $fiber === null ? ($this->main[$id] ??= new ExternalProbeState()) : $this->fiberState($id, $fiber);
        $confirmationRevision = $this->confirmationRevision;
        try {
            return $state->inspect(
                spl_object_id($container),
                function () use ($container, $id, $confirmationRevision): array {
                    $ownership = $this->observeLookup(static fn(): bool => AvailableEntryLookup::has($container, $id));
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
     * @return array{result:T,registryRevision:?int,reads:array<string,int>}
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
            'reads' => $observation['reads'] ?? [],
        ];
    }

    /**
     * @template T
     * @param Closure():T $operation
     * @return T
     */
    public function duringRegistration(Closure $operation): mixed
    {
        ++$this->activeRegistrations;
        try {
            return $operation();
        } finally {
            if (--$this->activeRegistrations === 0) {
                $this->registrationReads = [];
            }
        }
    }

    /** @param array<string,int> $reads */
    public function readsStillCurrent(array $reads): bool
    {
        foreach ($reads as $id => $revision) {
            if (($this->registrationReads[$id] ?? 0) > $revision) {
                return false;
            }
        }
        return true;
    }

    public function trackLookup(string $id): void
    {
        if ($this->mainObservations === [] && count($this->fiberObservations) === 0) {
            return;
        }
        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            $this->recordLookup($this->mainObservations, $id);
        } elseif (isset($this->fiberObservations[$fiber])) {
            $this->recordLookup($this->fiberObservations[$fiber], $id);
        }
    }

    /** @param list<LookupObservation> $observations */
    private function recordLookup(array &$observations, string $id): void
    {
        // A nested read also supplies evidence to the enclosing observers.
        foreach ($observations as &$observation) {
            if (!is_array($observation)) {
                $observation = [
                    'id' => $observation,
                    'registryRevision' => $this->revision,
                    'reads' => [],
                    'notified' => false,
                ];
            }
            $observation['registryRevision'] ??= $this->revision;
            $observation['reads'][$id] ??= $this->revision;
            if ($observation['id'] === null && $this->activeRegistrations !== 0) {
                $this->registrationReads[$id] ??= 0;
            }
        }
    }

    /** @param Fiber<mixed,mixed,mixed,mixed> $fiber */
    private function pushFiberObservation(Fiber $fiber, ?string $id): void
    {
        $this->fiberObservations[$fiber] ??= [];
        $this->fiberObservations[$fiber][] = $id;
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
     */
    public function invalidate(
        bool $appending = false,
        array $invalidated = [],
        array $directChanges = [],
        ?string $canonicalBinding = null,
    ): void {
        $this->lastUnscopedChange = ++$this->revision;
        if ($appending) {
            $this->membershipRevision = $this->revision;
        } else {
            $this->confirmationRevision = $this->revision;
        }
        if ($this->registrationReads !== []) {
            $this->recordChangedReads($invalidated, $directChanges, $canonicalBinding);
        }
    }

    /** @param list<string> $invalidated */
    public function noteInvalidatedEntries(array $invalidated): void
    {
        if ($this->registrationReads !== []) {
            $this->recordChangedReads($invalidated);
        }
    }

    /**
     * @param list<string> $invalidated
     * @param array<array-key,int|true> $directChanges
     */
    private function recordChangedReads(
        array $invalidated,
        array $directChanges = [],
        ?string $canonicalBinding = null,
    ): void {
        $changed = $directChanges + array_fill_keys($invalidated, true);
        foreach ($this->registrationReads as $id => $_) {
            if (isset($changed[$id]) || ($canonicalBinding !== null && $this->aliases->resolve((string) $id) === $canonicalBinding)) {
                $this->registrationReads[$id] = $this->revision;
            }
        }
    }

    /** @param list<string> $ids */
    public function notify(array $ids): void
    {
        $this->confirmationRevision = ++$this->revision;
        $this->noteInvalidatedEntries($ids);
        if ($this->mainObservations === [] && count($this->fiberObservations) === 0) {
            return;
        }
        $notifiedIds = array_fill_keys($ids, true);
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
                if (($id !== null && isset($ids[$id])) || array_intersect_key($observation['reads'], $ids) !== []) {
                    $observation['notified'] = true;
                }
            } elseif ($observation !== null && isset($ids[$observation])) {
                $observation = [
                    'id' => $observation,
                    'registryRevision' => null,
                    'reads' => [],
                    'notified' => true,
                ];
            }
        }
    }
}

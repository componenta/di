<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Closure;
use Fiber;
use Psr\Container\ContainerInterface;
use WeakMap;

/** Owns the active external probes for each lookup execution and entry ID. @internal */
final class ExternalLookupProbes
{
    /** @var array<string,ExternalProbeState> */
    private array $main = [];
    /** @var WeakMap<object,array<string,ExternalProbeState>> */
    private readonly WeakMap $fibers;
    /** @var list<int|null> */
    private array $mainObservations = [];
    /** @var WeakMap<object,list<int|null>> */
    private readonly WeakMap $fiberObservations;
    public private(set) int $revision = 0;
    public private(set) int $confirmationRevision = 0;
    public private(set) int $membershipRevision = 0;

    public function __construct()
    {
        $this->fibers = new WeakMap();
        $this->fiberObservations = new WeakMap();
    }

    public function start(string $id): ExternalProbeState
    {
        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            $state = $this->main[$id] ??= new ExternalProbeState();
            $this->mainObservations[] = null;
            return $state;
        }

        $state = $this->fiberState($id, $fiber);
        $this->pushFiberObservation($fiber);
        return $state;
    }

    public function finish(string $id, ExternalProbeState $state, bool $skipped): void
    {
        $fiber = Fiber::getCurrent();
        $registryRevision = $fiber === null ? array_pop($this->mainObservations) : $this->popFiberObservation($fiber);
        if ($state->finish()) {
            $this->release($id, $fiber);
        }
        if ($skipped || ($registryRevision !== null && $registryRevision !== $this->revision)) {
            // The selected route can finish. A skipped or superseded ownership
            // answer cannot supply a result to a shared cache.
            EntryLookupRevision::markIncomplete();
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
     * @return array{result:T,registryRevision:?int}
     */
    public function observeLookup(Closure $operation): array
    {
        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            $this->mainObservations[] = null;
        } else {
            $this->pushFiberObservation($fiber);
        }
        try {
            $result = $operation();
        } finally {
            $registryRevision = $fiber === null ? array_pop($this->mainObservations) : $this->popFiberObservation($fiber);
        }
        return ['result' => $result, 'registryRevision' => $registryRevision];
    }

    public function trackLookup(): void
    {
        if ($this->mainObservations === [] && count($this->fiberObservations) === 0) {
            return;
        }
        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            $this->recordLookup($this->mainObservations);
        } elseif (isset($this->fiberObservations[$fiber])) {
            $this->recordLookup($this->fiberObservations[$fiber]);
        }
    }

    /** @param list<int|null> $observations */
    private function recordLookup(array &$observations): void
    {
        // A nested read also supplies evidence to the enclosing observers.
        for ($index = count($observations) - 1; $index >= 0 && $observations[$index] === null; --$index) {
            $observations[$index] = $this->revision;
        }
    }

    /** @param Fiber<mixed,mixed,mixed,mixed> $fiber */
    private function pushFiberObservation(Fiber $fiber): void
    {
        $this->fiberObservations[$fiber] ??= [];
        $this->fiberObservations[$fiber][] = null;
    }

    /** @param Fiber<mixed,mixed,mixed,mixed> $fiber */
    private function popFiberObservation(Fiber $fiber): ?int
    {
        $revision = array_pop($this->fiberObservations[$fiber]);
        if ($this->fiberObservations[$fiber] === []) {
            unset($this->fiberObservations[$fiber]);
        }
        return $revision;
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

    public function invalidate(bool $appending = false): void
    {
        ++$this->revision;
        if ($appending) {
            $this->membershipRevision = $this->revision;
        } else {
            $this->confirmationRevision = $this->revision;
        }
    }
}

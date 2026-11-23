<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Closure;

/** Tracks the active providers and completed recursive probes for one entry ID. @internal */
final class ExternalProbeState
{
    /** @var array<int,array{activation:int,recursive:bool}> Active providers in call order. */
    private array $active = [];
    /** @var array<int,array{owns:bool,revision:int,registryRevision:?int}> Results of fresh inspections of active providers. */
    private array $confirmedOwnership = [];
    /** @var array<int,array<int,array<int,array{revision:int,activation:int}>>> Misses indexed by provider, lookup mode, and caller. */
    private array $recursiveMisses = [];
    private int $depth = 0;
    private int $activation = 0;

    public bool $isIdle {
        get => $this->depth === 0;
    }

    public function begin(): ?int
    {
        ++$this->depth;
        $caller = array_key_last($this->active);
        if ($caller !== null) {
            $this->active[$caller]['recursive'] = true;
        }
        return $caller;
    }

    public function finish(): bool
    {
        --$this->depth;
        if ($this->depth === 1) {
            // Each lookup made by the outer provider gets a fresh traversal.
            $this->recursiveMisses = [];
        }
        return $this->isIdle;
    }

    /** @param Closure():array{owns:bool,revision:int,registryRevision:?int} $check */
    public function inspect(int $provider, Closure $check): bool
    {
        // Registration gets fresh ownership evidence. Its nested lookups still
        // skip active providers, including the one being inspected here.
        $active = $this->active;
        $misses = $this->recursiveMisses;
        $confirmed = null;
        $lookupVersion = EntryLookupRevision::current();
        ++$this->depth;
        $this->recursiveMisses = [];
        unset($this->active[$provider], $this->confirmedOwnership[$provider]);
        $this->enterProvider($provider);
        try {
            $result = $check();
            if (isset($active[$provider]) && EntryLookupRevision::current() === $lookupVersion) {
                $confirmed = $result;
            }
            return $result['owns'];
        } finally {
            $this->active = $active;
            $this->recursiveMisses = $misses;
            if ($confirmed === null) {
                unset($this->confirmedOwnership[$provider]);
            } else {
                $this->confirmedOwnership[$provider] = $confirmed;
            }
            --$this->depth;
        }
    }

    public function confirmedOwnership(int $provider, int $revision, int $registryRevision): ?bool
    {
        $confirmed = $this->confirmedOwnership[$provider] ?? null;
        if ($confirmed === null || $confirmed['revision'] !== $revision
            || ($confirmed['registryRevision'] !== null && $confirmed['registryRevision'] !== $registryRevision)
        ) {
            return null;
        }
        return $confirmed['owns'];
    }

    public function canProbe(int $provider, ?int $caller, bool $requireResolvable, int $revision): bool
    {
        if (isset($this->active[$provider])) {
            return false;
        }
        if ($caller === null) {
            return true;
        }

        $miss = $this->recursiveMisses[$provider][(int) $requireResolvable][$caller] ?? null;
        // An explicit new query in the same callback rechecks its own misses.
        return $miss === null
            || $miss['revision'] !== $revision
            || $miss['activation'] === $this->active[$caller]['activation'];
    }

    public function enterProvider(int $provider): void
    {
        $this->active[$provider] = ['activation' => ++$this->activation, 'recursive' => false];
    }

    public function leaveProvider(int $provider): void
    {
        unset($this->active[$provider], $this->confirmedOwnership[$provider]);
    }

    public function recordRecursiveMiss(int $caller, int $provider, bool $requireResolvable, int $revision): void
    {
        if ($this->active[$provider]['recursive']) {
            $this->recursiveMisses[$provider][(int) $requireResolvable][$caller] = [
                'revision' => $revision,
                'activation' => $this->active[$caller]['activation'],
            ];
        }
    }

    public function recordOwner(int $provider): void
    {
        unset($this->recursiveMisses[$provider]);
    }
}

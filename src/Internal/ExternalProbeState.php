<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

/** Tracks the active providers and completed recursive probes for one entry ID. @internal */
final class ExternalProbeState
{
    /** @var array<int,array{activation:int,recursive:bool}> Active providers in call order. */
    private array $active = [];
    /** @var array<int,array<int,array<int,array{revision:int,activation:int}>>> Misses indexed by provider, lookup mode, and caller. */
    private array $recursiveMisses = [];
    private int $depth = 0;
    private int $activation = 0;

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
        return $this->depth === 0;
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
        unset($this->active[$provider]);
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

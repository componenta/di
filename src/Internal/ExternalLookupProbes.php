<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Fiber;
use WeakMap;

/** Owns the active external probes for each lookup execution and entry ID. @internal */
final class ExternalLookupProbes
{
    /** @var array<string,ExternalProbeState> */
    private array $main = [];
    /** @var WeakMap<object,array<string,ExternalProbeState>> Fiber-specific lookups. */
    private readonly WeakMap $fibers;
    public private(set) int $revision = 0;

    public function __construct()
    {
        $this->fibers = new WeakMap();
    }

    public function start(string $id): ExternalProbeState
    {
        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            return $this->main[$id] ??= new ExternalProbeState();
        }

        $states = $this->fibers[$fiber] ?? [];
        $state = $states[$id] ??= new ExternalProbeState();
        $this->fibers[$fiber] = $states;
        return $state;
    }

    public function finish(string $id, ExternalProbeState $state): void
    {
        if (!$state->finish()) {
            return;
        }

        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            unset($this->main[$id]);
            return;
        }

        $states = $this->fibers[$fiber] ?? [];
        unset($states[$id]);
        if ($states === []) {
            unset($this->fibers[$fiber]);
            return;
        }
        $this->fibers[$fiber] = $states;
    }

    public function invalidate(): void
    {
        ++$this->revision;
    }
}

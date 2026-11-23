<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Fiber;
use Psr\Container\ContainerInterface;
use WeakMap;

/** Owns the active external probes for each lookup execution and entry ID. @internal */
final class ExternalLookupProbes
{
    /** @var array<string,ExternalProbeState> */
    private array $main = [];
    private int $mainVisits = 0;
    /** @var WeakMap<object,array{states:array<string,ExternalProbeState>,visits:int}> */
    private readonly WeakMap $fibers;
    public private(set) int $revision = 0;
    public private(set) int $confirmationRevision = 0;

    public function __construct()
    {
        $this->fibers = new WeakMap();
    }

    public function start(string $id): ExternalProbeState
    {
        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            ++$this->mainVisits;
            return $this->main[$id] ??= new ExternalProbeState();
        }

        $execution = $this->fibers[$fiber] ?? ['states' => [], 'visits' => 0];
        $state = $execution['states'][$id] ??= new ExternalProbeState();
        ++$execution['visits'];
        $this->fibers[$fiber] = $execution;
        return $state;
    }

    public function finish(string $id, ExternalProbeState $state): void
    {
        if ($state->finish()) {
            $this->release($id);
        }
    }

    public function inspect(ContainerInterface $container, string $id): bool
    {
        $state = $this->start($id);
        $fiber = Fiber::getCurrent();
        $visits = $this->visits($fiber);
        $revision = $this->revision;
        $confirmationRevision = $this->confirmationRevision;
        try {
            return $state->inspect(
                spl_object_id($container),
                function () use ($container, $id, $fiber, $visits, $revision, $confirmationRevision): array {
                    $owns = AvailableEntryLookup::has($container, $id);
                    return [
                        'owns' => $owns,
                        'revision' => $confirmationRevision,
                        // An answer based on the registry can change when a provider is added.
                        'registryRevision' => $this->visits($fiber) === $visits ? null : $revision,
                    ];
                },
            );
        } finally {
            if ($state->isIdle) {
                $this->release($id);
            }
        }
    }

    /**
     * @param Fiber<mixed,mixed,mixed,mixed>|null $fiber
     * @phpstan-impure
     */
    private function visits(?Fiber $fiber): int
    {
        return $fiber === null ? $this->mainVisits : ($this->fibers[$fiber]['visits'] ?? 0);
    }

    private function release(string $id): void
    {
        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            unset($this->main[$id]);
            if ($this->main === []) {
                $this->mainVisits = 0;
            }
            return;
        }

        $execution = $this->fibers[$fiber];
        unset($execution['states'][$id]);
        if ($execution['states'] === []) {
            unset($this->fibers[$fiber]);
            return;
        }
        $this->fibers[$fiber] = $execution;
    }

    public function invalidate(bool $appending = false): void
    {
        ++$this->revision;
        // Appending advances the walk through providers in registration order.
        if (!$appending) {
            $this->confirmationRevision = $this->revision;
        }
    }
}

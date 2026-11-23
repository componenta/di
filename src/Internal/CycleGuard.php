<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Componenta\DI\Exception\CircularDependencyException;
use Componenta\DI\Exception\ConcurrentResolutionException;
use Fiber;
use WeakMap;
use WeakReference;

/**
 * Tracks the in-flight resolution stack so the container can detect and
 * report circular dependencies.
 *
 * Every resolution path must pair {@see self::enter()} with
 * {@see self::leave()} in a finally block.
 *
 * @internal
 */
final class CycleGuard
{
    /** @var array<string, true> Main execution-context resolution stack. */
    private array $resolving = [];

    /** @var WeakMap<object, array<string, true>> Fiber-keyed execution stacks. */
    private readonly WeakMap $fiberStacks;

    /** @var array<string, object> Shared-entry owner token by id. */
    private array $sharedOwners = [];

    /** Main execution-context owner token. */
    private readonly object $mainOwner;

    public bool $isActive {
        get => $this->resolving !== [] || count($this->fiberStacks) !== 0;
    }

    public function __construct()
    {
        $this->fiberStacks = new WeakMap();
        $this->mainOwner = new \stdClass();
    }

    public function contains(string $id): bool
    {
        $execution = Fiber::getCurrent();
        return $execution === null
            ? isset($this->resolving[$id])
            : isset($this->fiberStacks[$execution][$id]);
    }

    /** Checks for a cycle without adding another step to the resolution stack. */
    public function assertInactive(string $id): void
    {
        $execution = Fiber::getCurrent();
        $stack = $execution === null ? $this->resolving : ($this->fiberStacks[$execution] ?? []);
        if (isset($stack[$id])) {
            throw CircularDependencyException::forService([...array_map('strval', array_keys($stack)), $id]);
        }
    }

    /**
     * Marks `$id` as in-flight.
     *
     * @throws CircularDependencyException If `$id` is already on the stack.
     */
    public function enter(string $id, ?object $authority = null): void
    {
        RuntimeCollaboratorOwnership::assertWritable($this, $authority);
        $execution = Fiber::getCurrent();

        if ($execution === null) {
            if (isset($this->resolving[$id])) {
                throw CircularDependencyException::forService([
                    ...array_map('strval', array_keys($this->resolving)),
                    $id,
                ]);
            }

            $this->resolving[$id] = true;
            return;
        }

        $stack = $this->fiberStacks[$execution] ?? [];

        if (isset($stack[$id])) {
            throw CircularDependencyException::forService([
                ...array_map('strval', array_keys($stack)),
                $id,
            ]);
        }

        $stack[$id] = true;
        $this->fiberStacks[$execution] = $stack;
    }

    public function leave(string $id, ?object $authority = null): void
    {
        RuntimeCollaboratorOwnership::assertWritable($this, $authority);
        $this->leaveContext($id, Fiber::getCurrent());
    }

    public function enterShared(string $id, ?object $authority = null): void
    {
        RuntimeCollaboratorOwnership::assertWritable($this, $authority);
        $execution = Fiber::getCurrent();
        if ($execution === null) {
            if (isset($this->resolving[$id])) {
                throw CircularDependencyException::forService([
                    ...array_map('strval', array_keys($this->resolving)),
                    $id,
                ]);
            }
        } else {
            $stack = $this->fiberStacks[$execution] ?? [];
            if (isset($stack[$id])) {
                throw CircularDependencyException::forService([
                    ...array_map('strval', array_keys($stack)),
                    $id,
                ]);
            }
        }

        $owner = $this->sharedOwners[$id] ?? null;
        if ($owner instanceof WeakReference && $owner->get() === null) {
            unset($this->sharedOwners[$id]);
            $owner = null;
        }

        if ($owner !== null && !$this->ownedBy($owner, $execution)) {
            throw ConcurrentResolutionException::forService($id);
        }

        if ($execution === null) {
            $this->resolving[$id] = true;
        } else {
            $stack[$id] = true;
            $this->fiberStacks[$execution] = $stack;
        }
        $this->sharedOwners[$id] = $execution === null
            ? $this->mainOwner
            : WeakReference::create($execution);
    }

    public function leaveShared(string $id, ?object $authority = null): void
    {
        RuntimeCollaboratorOwnership::assertWritable($this, $authority);
        $execution = Fiber::getCurrent();
        $owner = $this->sharedOwners[$id] ?? null;

        if ($owner !== null && $this->ownedBy($owner, $execution)) {
            unset($this->sharedOwners[$id]);
        }

        $this->leaveContext($id, $execution);
    }

    private function ownedBy(object $owner, ?object $execution): bool
    {
        if ($execution === null) {
            return $owner === $this->mainOwner;
        }

        return $owner instanceof WeakReference
            && $owner->get() === $execution;
    }

    /** @param Fiber<mixed, mixed, mixed, mixed>|null $execution */
    private function leaveContext(string $id, ?Fiber $execution): void
    {
        if ($execution === null) {
            unset($this->resolving[$id]);
            return;
        }

        $stack = $this->fiberStacks[$execution] ?? [];
        unset($stack[$id]);

        if ($stack === []) {
            unset($this->fiberStacks[$execution]);
            return;
        }

        $this->fiberStacks[$execution] = $stack;
    }

    private function __clone() {}
}

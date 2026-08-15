<?php

declare(strict_types=1);

namespace Componenta\DI;

use Componenta\DI\Exception\CircularDependencyException;
use Componenta\DI\Exception\ConcurrentResolutionException;
use Fiber;
use WeakMap;

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
    private WeakMap $fiberStacks;

    /** @var array<string, object|null> Shared-entry owner by id (Fiber or main context). */
    private array $sharedOwners = [];

    public function __construct()
    {
        $this->fiberStacks = new WeakMap();
    }

    /**
     * Marks `$id` as in-flight.
     *
     * @throws CircularDependencyException If `$id` is already on the stack.
     */
    public function enter(string $id): void
    {
        $stack = $this->stack();

        if (isset($stack[$id])) {
            throw CircularDependencyException::forService([
                ...array_keys($stack),
                $id,
            ]);
        }

        $stack[$id] = true;
        $this->replaceStack($stack);
    }

    public function leave(string $id): void
    {
        $stack = $this->stack();
        unset($stack[$id]);
        $this->replaceStack($stack);
    }

    /**
     * Enters a shared-entry construction without conflating another Fiber
     * with recursion in the current dependency graph.
     */
    public function enterShared(string $id): void
    {
        $execution = Fiber::getCurrent();
        $stack = $this->stack();

        if (isset($stack[$id])) {
            throw CircularDependencyException::forService([
                ...array_keys($stack),
                $id,
            ]);
        }

        if (array_key_exists($id, $this->sharedOwners)
            && $this->sharedOwners[$id] !== $execution
        ) {
            throw ConcurrentResolutionException::forService($id);
        }

        $this->enter($id);
        $this->sharedOwners[$id] = $execution;
    }

    public function leaveShared(string $id): void
    {
        $execution = Fiber::getCurrent();

        if (array_key_exists($id, $this->sharedOwners)
            && $this->sharedOwners[$id] === $execution
        ) {
            unset($this->sharedOwners[$id]);
        }

        $this->leave($id);
    }

    /** @return array<string, true> */
    private function stack(): array
    {
        $fiber = Fiber::getCurrent();

        return $fiber === null
            ? $this->resolving
            : ($this->fiberStacks[$fiber] ?? []);
    }

    /** @param array<string, true> $stack */
    private function replaceStack(array $stack): void
    {
        $fiber = Fiber::getCurrent();

        if ($fiber === null) {
            $this->resolving = $stack;
            return;
        }

        if ($stack === []) {
            unset($this->fiberStacks[$fiber]);
            return;
        }

        $this->fiberStacks[$fiber] = $stack;
    }
}

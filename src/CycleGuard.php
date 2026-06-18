<?php

declare(strict_types=1);

namespace Componenta\DI;

use Closure;
use Componenta\DI\Exception\CircularDependencyException;

/**
 * Tracks the in-flight resolution stack so the container can detect and
 * report circular dependencies.
 *
 * Use {@see self::track()} to wrap a resolution step - the guard enters the id
 * into the stack before calling the action and leaves it afterwards even if
 * the action throws. Direct {@see self::enter()}/{@see self::leave()} calls
 * are available for cases where the call pattern is not a single closure.
 *
 * @internal
 */
final class CycleGuard
{
    /** @var array<string, true> Current resolution stack. */
    private array $resolving = [];

    /**
     * Marks `$id` as in-flight.
     *
     * @throws CircularDependencyException If `$id` is already on the stack.
     */
    public function enter(string $id): void
    {
        if (isset($this->resolving[$id])) {
            throw CircularDependencyException::forService([
                ...array_keys($this->resolving),
                $id,
            ]);
        }

        $this->resolving[$id] = true;
    }

    public function leave(string $id): void
    {
        unset($this->resolving[$id]);
    }

    /**
     * Wraps a resolution step in {@see enter()} / {@see leave()}. The guard
     * is released even if the action throws.
     *
     * @template T
     * @param Closure(): T $action
     * @return T
     *
     * @throws CircularDependencyException
     */
    public function track(string $id, Closure $action): mixed
    {
        $this->enter($id);
        try {
            return $action();
        } finally {
            $this->leave($id);
        }
    }
}

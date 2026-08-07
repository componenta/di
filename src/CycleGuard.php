<?php

declare(strict_types=1);

namespace Componenta\DI;

use Componenta\DI\Exception\CircularDependencyException;

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
}

<?php

declare(strict_types=1);

namespace Componenta\DI;

/**
 * Tracks ids whose resolver binding was installed through Container::set().
 *
 * Stored in a dedicated mutable collaborator because Container is readonly;
 * configured factories/invokables are deliberately not tracked here, so a
 * plain stored value continues to affect get() without changing make() unless
 * it is replacing an explicit runtime definition.
 *
 * @internal
 */
final class RuntimeDefinitionRegistry
{
    /** @var array<string, true> */
    private array $ids = [];

    public function mark(string $id): void
    {
        $this->ids[$id] = true;
    }

    public function has(string $id): bool
    {
        return isset($this->ids[$id]);
    }

    public function clear(string $id): void
    {
        unset($this->ids[$id]);
    }
}

<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Closure;
use Fiber;
use WeakMap;

/** Observes the direct lookups used to select a standalone delegator. @internal */
final class DelegatorLookupScope
{
    /** @var (Closure(string):void)|null */
    private ?Closure $main = null;
    /** @var WeakMap<object,Closure(string):void>|null */
    private ?WeakMap $fibers = null;

    /**
     * @param (Closure(string):void)|null $observer
     * @return (Closure(string):void)|null
     */
    public function replace(?Closure $observer): ?Closure
    {
        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            $previous = $this->main;
            $this->main = $observer;
            return $previous;
        }

        $previous = $this->fibers[$fiber] ?? null;
        if ($observer !== null) {
            $fibers = $this->fibers ??= new WeakMap();
            $fibers[$fiber] = $observer;
        } elseif ($previous !== null) {
            unset($this->fibers[$fiber]);
        }
        return $previous;
    }

    public function record(string $id): void
    {
        $this->current()?->__invoke($id);
    }

    /** @return (Closure(string):void)|null */
    public function beginLookup(string $id): ?Closure
    {
        $observer = $this->current();
        if ($observer !== null) {
            // The selected entry owns any lookups made while finding or building it.
            $this->replace(null);
            $observer($id);
        }
        return $observer;
    }

    /** @return (Closure(string):void)|null */
    private function current(): ?Closure
    {
        if ($this->main === null && $this->fibers === null) {
            return null;
        }
        $fiber = Fiber::getCurrent();
        return $fiber === null ? $this->main : ($this->fibers[$fiber] ?? null);
    }
}

<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Parameter\Request;

use Closure;
use WeakMap;

/** Keeps source checks for results while the mapper still owns their inputs. @internal */
final class MappedRequestPublication
{
    /** @var WeakMap<object,list<Closure():void>>|null */
    private ?WeakMap $checks = null;
    public private(set) bool $isOpen = true;

    /** @param list<Closure():void> $checks */
    public function record(object $entry, array $checks): void
    {
        if ($this->isOpen && $checks !== []) {
            $pending = $this->checks ??= new WeakMap();
            $pending[$entry] = [...($pending[$entry] ?? []), ...$checks];
        }
    }

    /** @return list<Closure():void> */
    public function finish(?object $result): array
    {
        $checks = $result === null ? [] : ($this->checks[$result] ?? []);
        $this->isOpen = false;
        $this->checks = null;
        foreach ($checks as $check) {
            $check();
        }
        return $checks;
    }
}

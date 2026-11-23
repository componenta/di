<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Componenta\DI\Exception\ResolutionException;
use Fiber;
use WeakMap;

/** Tracks incomplete lookups across containers in the current execution. @internal */
final class EntryLookupRevision
{
    private static int $main = 0;
    /** @var WeakMap<object,int>|null */
    private static ?WeakMap $fibers = null;

    /** @phpstan-impure */
    public static function current(): int
    {
        if (self::$main === 0 && self::$fibers === null) {
            return 0;
        }
        $fiber = Fiber::getCurrent();
        return $fiber === null ? self::$main : (self::$fibers[$fiber] ?? 0);
    }

    public static function markIncomplete(): void
    {
        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            ++self::$main;
            return;
        }

        $fibers = self::$fibers ??= new WeakMap();
        $fibers[$fiber] = ($fibers[$fiber] ?? 0) + 1;
    }

    public static function assertCompleteSince(int $revision, string $entry): void
    {
        if (self::current() !== $revision) {
            // Throw before native lazy initialization can commit the temporary value.
            throw new ResolutionException(
                sprintf('Cannot initialize deferred entry "%s" while an external dependency lookup is incomplete.', $entry),
                serviceId: $entry,
            );
        }
    }

    private function __construct() {}
}

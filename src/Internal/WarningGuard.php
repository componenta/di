<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

/**
 * Executes exactly one native operation while containing E_WARNING diagnostics.
 *
 * PHP still invokes user error handlers for diagnostics suppressed with `@`.
 * Native I/O boundaries that intentionally convert a false return value into a
 * package exception therefore use this guard instead of relying on `@`.
 *
 * @internal
 */
final class WarningGuard
{
    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public static function run(callable $operation): mixed
    {
        set_error_handler(
            static fn(int $_severity, string $_message, string $_file, int $_line): bool => true,
            E_WARNING,
        );

        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }

    private function __construct() {}
}

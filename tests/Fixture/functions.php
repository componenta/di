<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixture;

if (!function_exists(__NAMESPACE__ . '\\globalCallableFixture')) {
    /**
     * Plain namespaced function used by CallableResolver tests to cover the
     * "function_exists()" branch of string resolution.
     */
    function globalCallableFixture(int $x = 10): int
    {
        return $x * 2;
    }
}

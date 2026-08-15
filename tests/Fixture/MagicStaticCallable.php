<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixture;

final class MagicStaticCallable
{
    /** @param array<int, mixed> $arguments */
    public static function __callStatic(string $name, array $arguments): array
    {
        return [$name, $arguments];
    }
}

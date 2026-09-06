<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Support;

final class TestCounter
{
    public static int $value = 0;

    public static function reset(): void
    {
        self::$value = 0;
    }

    public static function next(): int
    {
        return ++self::$value;
    }
}

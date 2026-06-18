<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixture;

use Componenta\DI\Attribute\Init;

final class InitStaticMethodFixture
{
    #[Init([self::class, 'generate'])]
    public string $value;

    public static function generate(): string
    {
        return 'generated';
    }
}

final class InitWithParamsFixture
{
    #[Init('date', ['format' => 'Y-m-d'])]
    public string $date;
}

final class InitArrayCallableFixture
{
    #[Init([UuidGenerator::class, 'generate'])]
    public string $id;
}

final class InitFunctionFixture
{
    #[Init('time')]
    public int $timestamp;
}

final class NoInitFixture
{
    public string $value;
}

final class UuidGenerator
{
    public static function generate(): string
    {
        return 'uuid-' . random_int(1000, 9999);
    }
}

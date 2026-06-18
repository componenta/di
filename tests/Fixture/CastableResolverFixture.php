<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixture;

use Componenta\DI\Attribute\Cast;

final class CastIntFixture
{
    #[Cast('int')]
    public int $age;
}

final class CastEmailFixture
{
    #[Cast('email')]
    public string $email;
}

final class CastPipeFixture
{
    #[Cast('base64|json')]
    public array $data;
}

final class CastWithDefaultFixture
{
    #[Cast('int', default: 10)]
    public int $limit;
}

final class NoCastFixture
{
    public string $value;
}

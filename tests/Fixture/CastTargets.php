<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixture;

use Componenta\DI\Attribute\Cast;

final class CastTargets
{
    #[Cast('int')]
    public int $age;

    #[Cast('int', default: 'default-raw')]
    public int $withDefault;

    public int $unattributed;

    public function byParameters(
        #[Cast('int')] int $age,
        #[Cast('int', default: 'attr-default')] int $withAttrDefault,
        #[Cast('int')] ?int $allowsNull,
        #[Cast('int')] int $withCtorDefault = 42,
        #[Cast('int')] int $strict = -1,
        int $plain = 0,
    ): void {}

    public function requiredOnly(#[Cast('int')] int $age): void {}
}

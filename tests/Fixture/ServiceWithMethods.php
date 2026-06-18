<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixture;

final class ServiceWithMethods
{
    public static function staticMethod(int $x = 1): string
    {
        return 'static:' . $x;
    }

    public function instanceMethod(int $x = 1): string
    {
        return 'instance:' . $x;
    }
}

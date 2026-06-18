<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixture;

final class NonInvokableService
{
    public function doSomething(): string
    {
        return 'did';
    }
}

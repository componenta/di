<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixture;

final class SimpleService
{
    public bool $constructed;

    public function __construct()
    {
        $this->constructed = true;
    }
}

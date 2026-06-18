<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixture;

class FakeUser
{
    public function __construct(public string $name = 'default') {}
}

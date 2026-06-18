<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixture;

use Componenta\DI\Attribute\SetUp;

#[SetUp('runWithDep')]
final class SetUpContextUser
{
    public mixed $received = null;

    public function runWithDep(string $dep): void
    {
        $this->received = $dep;
    }
}

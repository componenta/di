<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixture;

use Componenta\DI\Attribute\SetUp;

#[SetUp('configure', ['timeout' => 120])]
#[SetUp('boot')]
final class SetUpTarget
{
    public array $log = [];

    public function configure(int $timeout, string $label = 'default'): void
    {
        $this->log[] = ['configure', $timeout, $label];
    }

    public function boot(): void
    {
        $this->log[] = ['boot'];
    }
}

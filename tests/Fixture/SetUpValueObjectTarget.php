<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixture;

use Componenta\Config\ConfigEntry;
use Componenta\Config\ConfigPath;
use Componenta\Config\ContainerEntry;
use Componenta\DI\Attribute\SetUp;

#[SetUp('configure', [
    'service' => new ContainerEntry('setup.service', \stdClass::class),
    'name' => new ConfigEntry(new ConfigPath('app.name')),
])]
final class SetUpValueObjectTarget
{
    public ?object $service = null;
    public ?string $name = null;

    public function configure(object $service, string $name): void
    {
        $this->service = $service;
        $this->name = $name;
    }
}

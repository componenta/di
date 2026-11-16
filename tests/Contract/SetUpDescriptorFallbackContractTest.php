<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\Config\Config;
use Componenta\Config\ConfigPath;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\Attribute\Env;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;

#[SetUp('configure', ['port' => new Env('MISSING_PORT', default: 8123)])]
final class SetUpEnvironmentFallbackTarget
{
    public int $port = 0;
    public int $calls = 0;

    public function configure(int $port): void
    {
        $this->port = $port;
        ++$this->calls;
    }
}

#[SetUp('configure', [
    'name' => new ConfigAttribute(new ConfigPath('settings.name')),
    'fallback' => new ConfigAttribute(new ConfigPath('settings.missing'), default: 'descriptor default'),
])]
final class SetUpConfigurationPathTarget
{
    public string $name = '';
    public string $fallback = '';
    public int $calls = 0;

    public function configure(string $name, string $fallback): void
    {
        $this->name = $name;
        $this->fallback = $fallback;
        ++$this->calls;
    }
}

test('SetUp uses an Env descriptor default when the environment variable is absent', function (): void {
    $container = (new ContainerFactory())->create(
        new Config([], new Environment([])),
        new DependencyDefinitions([]),
    )->container;

    if (!$container instanceof Container) {
        throw new \RuntimeException('ContainerFactory returned an unsupported container implementation.');
    }

    $entry = $container->make(SetUpEnvironmentFallbackTarget::class);

    expect($entry->port)->toBe(8123)
        ->and($entry->calls)->toBe(1);
});

test('SetUp uses explicit Config descriptor paths and their defaults instead of parameter keys', function (): void {
    $container = (new ContainerFactory())->create(
        new Config([
            'name' => 'wrong parameter key',
            'fallback' => 'wrong fallback key',
            'settings' => ['name' => 'configured path'],
        ], new Environment([])),
        new DependencyDefinitions([]),
    )->container;

    if (!$container instanceof Container) {
        throw new \RuntimeException('ContainerFactory returned an unsupported container implementation.');
    }

    $entry = $container->make(SetUpConfigurationPathTarget::class);

    expect($entry->name)->toBe('configured path')
        ->and($entry->fallback)->toBe('descriptor default')
        ->and($entry->calls)->toBe(1);
});

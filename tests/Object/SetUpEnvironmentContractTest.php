<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Object\SetUpEnvironmentContract;

use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\Config\EnvironmentEntry;
use Componenta\DI\Attribute\Env;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;
use PHPUnit\Framework\Assert;

#[SetUp('configure', [
    'port' => new Env('PORT'),
    'runtimePort' => new EnvironmentEntry('PORT'),
    'debug' => new Env('DEBUG'),
    'runtimeDebug' => new EnvironmentEntry('DEBUG'),
])]
final class SetUpEnvironmentTarget
{
    public int $port = 0;
    public int $runtimePort = 0;
    public bool $debug;
    public bool $runtimeDebug;

    public function configure(
        int $port,
        int $runtimePort,
        bool $debug,
        bool $runtimeDebug,
    ): void {
        $this->port = $port;
        $this->runtimePort = $runtimePort;
        $this->debug = $debug;
        $this->runtimeDebug = $runtimeDebug;
    }
}

test('SetUp resolves typed environment descriptors through the public runtime factory', function (
    string $port,
    string $debug,
    int $expectedPort,
    bool $expectedDebug,
): void {
    $environment = new Environment([
        'PORT' => $port,
        'DEBUG' => $debug,
    ]);
    $config = new Config([], $environment);
    $container = (new ContainerFactory())->create(
        $config,
        new DependencyDefinitions([]),
    )->container;

    if (!$container instanceof Container) {
        throw new \RuntimeException('ContainerFactory returned an unsupported container implementation.');
    }

    $entry = $container->make(SetUpEnvironmentTarget::class);
    Assert::assertInstanceOf(SetUpEnvironmentTarget::class, $entry);

    expect($entry->port)->toBe($expectedPort)
        ->and($entry->runtimePort)->toBe($expectedPort)
        ->and($entry->debug)->toBe($expectedDebug)
        ->and($entry->runtimeDebug)->toBe($expectedDebug)
        ->and($container->get(Config::class))->toBe($config)
        ->and($container->get(Environment::class))->toBe($environment);
})->with([
    'enabled' => ['9001', 'yes', 9001, true],
    'disabled' => ['9090', 'no', 9090, false],
]);

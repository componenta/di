<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\Config\EnvironmentEntry;
use Componenta\DI\Attribute\Env;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;

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
    public bool $debug = false;
    public bool $runtimeDebug = false;

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

test('SetUp resolves typed environment descriptors through the public runtime factory', function (string $mode): void {
    $environment = new Environment([
        'APP_ENV' => $mode,
        'PORT' => '9001',
        'DEBUG' => 'yes',
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

    expect($entry->port)->toBe(9001)
        ->and($entry->runtimePort)->toBe(9001)
        ->and($entry->debug)->toBeTrue()
        ->and($entry->runtimeDebug)->toBeTrue()
        ->and($container->get(Config::class))->toBe($config)
        ->and($container->get(Environment::class))->toBe($environment)
        ->and($environment->get('APP_ENV'))->toBe($mode);
})->with(['development', 'production']);

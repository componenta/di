<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\Config\Config;
use Componenta\Config\Environment;
use Componenta\Config\EnvironmentEntry;
use Componenta\DI\Attribute\Env;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;

#[SetUp('configure', [
    'port' => new Env('PORT'),
    'runtimePort' => new EnvironmentEntry('PORT'),
    'debug' => new Env('DEBUG'),
    'runtimeDebug' => new EnvironmentEntry('DEBUG'),
])]
final class AotSetUpEnvironmentTarget
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

function cleanupAotSetUpEnvironmentDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }

    @rmdir($directory);
}

test('typed SetUp environment descriptors execute identically in reflection and compiled factories', function (): void {
    $directory = sys_get_temp_dir()
        . DIRECTORY_SEPARATOR
        . 'componenta-di-v5-setup-env-aot-'
        . bin2hex(random_bytes(5));
    $environment = new Environment([
        'PORT' => '9001',
        'DEBUG' => 'yes',
    ]);
    $config = new Config([], $environment);
    $builder = ContainerBuilder::configure($config);
    $development = $builder->build();

    try {
        $compiled = $builder->compileFactories([AotSetUpEnvironmentTarget::class], $directory);
        $data = $builder->toArray();
        $dependencies = $data[ConfigKey::DEPENDENCIES] ?? [];
        expect($dependencies)->toBeArray();

        $dependencies[ConfigKey::FACTORIES] = array_replace(
            $dependencies[ConfigKey::FACTORIES] ?? [],
            $compiled,
        );

        $production = ContainerBuilder::configureFromCache(
            new Config([], $environment),
            [
                'version' => ContainerBuilder::CACHE_VERSION,
                ConfigKey::DEPENDENCIES => $dependencies,
            ],
            $directory,
        )->build();

        foreach ([$development, $production] as $container) {
            $entry = $container->make(AotSetUpEnvironmentTarget::class);

            expect($entry->port)->toBe(9001)
                ->and($entry->runtimePort)->toBe(9001)
                ->and($entry->debug)->toBeTrue()
                ->and($entry->runtimeDebug)->toBeTrue()
                ->and($container->get(Environment::class))->toBe($environment);
        }
    } finally {
        cleanupAotSetUpEnvironmentDirectory($directory);
    }
});

<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\Config\Config;
use Componenta\Config\ConfigLoader;
use Componenta\Config\Environment;
use Componenta\DI\Cache\DiCacheGenerator;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;

use function Componenta\Config\env;

test('persistent config and DI caches preserve config v3 runtime semantics end to end', function (): void {
    $directory = sys_get_temp_dir()
        . DIRECTORY_SEPARATOR
        . 'componenta-di-config-v3-parity-'
        . bin2hex(random_bytes(6));
    $configFile = $directory . DIRECTORY_SEPARATOR . 'config.php';
    $diFile = $directory . DIRECTORY_SEPARATOR . 'di.php';

    try {
        $application = [
            'api.token' => env('APP_TOKEN'),
            'api.port' => env('APP_PORT', '8080'),
        ];
        $dependencies = [
            ConfigKey::SERVICES => [
                'configured.service' => 'ready',
            ],
        ];

        $buildConfig = new Config(
            [
                ...$application,
                ConfigKey::DEPENDENCIES => $dependencies,
            ],
            new Environment([
                'APP_TOKEN' => 'build-secret',
                'APP_PORT' => '9999',
            ]),
        );

        ConfigLoader::export($buildConfig, $configFile);
        (new DiCacheGenerator())->generate($dependencies, $diFile);

        $configCacheSource = file_get_contents($configFile);
        $diCacheSource = file_get_contents($diFile);

        expect($configCacheSource)->toBeString()
            ->and($diCacheSource)->toBeString()
            ->and($configCacheSource)->not->toContain('build-secret')
            ->and($configCacheSource)->not->toContain('9999')
            ->and($diCacheSource)->not->toContain('build-secret')
            ->and($diCacheSource)->not->toContain('9999');

        $runtimeEnvironment = new Environment([
            'APP_TOKEN' => 'runtime-token',
            'APP_PORT' => '8081',
        ]);

        $providerContainer = ContainerBuilder::configure(new Config(
            [
                ...$application,
                ConfigKey::DEPENDENCIES => $dependencies,
            ],
            $runtimeEnvironment,
        ))->build();

        $cachedConfig = ConfigLoader::loadFromFile($configFile, $runtimeEnvironment);
        $diCache = require $diFile;
        expect($diCache)->toBeArray();

        $cachedContainer = ContainerBuilder::configureFromCache(
            $cachedConfig,
            $diCache,
            baseDir: $directory,
        )->build();

        foreach ([$providerContainer, $cachedContainer] as $container) {
            $runtimeConfig = $container->get(Config::class);

            expect($runtimeConfig)->toBeInstanceOf(Config::class)
                ->and($runtimeConfig->environment)->toBe($runtimeEnvironment)
                ->and($container->get(Environment::class))->toBe($runtimeEnvironment)
                ->and($runtimeConfig->string('api.token'))->toBe('runtime-token')
                ->and($runtimeConfig->int('api.port'))->toBe(8081)
                ->and($container->get('configured.service'))->toBe('ready');
        }

        expect($providerContainer->get(Config::class)->toArray())
            ->toBe($cachedContainer->get(Config::class)->toArray());
    } finally {
        foreach ([$configFile, $diFile] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        if (is_dir($directory)) {
            @rmdir($directory);
        }
    }
});

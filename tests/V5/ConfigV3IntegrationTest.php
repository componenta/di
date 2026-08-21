<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\Config\Config;
use Componenta\Config\ConfigKey as BaseConfigKey;
use Componenta\Config\Environment;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;

use function Componenta\Config\env;

test('config v3 runtime environment is preserved as one container snapshot', function (): void {
    $environment = new Environment([
        'APP_TOKEN' => 'runtime-token',
    ]);
    $config = new Config([
        'token' => env('APP_TOKEN'),
        ConfigKey::DEPENDENCIES => [
            ConfigKey::SERVICES => [
                'configured.service' => 'ready',
            ],
        ],
    ], $environment);

    $container = ContainerBuilder::configure($config)->build();
    $runtimeConfig = $container->get(Config::class);

    expect($runtimeConfig)->toBeInstanceOf(Config::class)
        ->and($runtimeConfig->environment)->toBe($environment)
        ->and($container->get(Environment::class))->toBe($environment)
        ->and($runtimeConfig->string('token'))->toBe('runtime-token')
        ->and($container->get('configured.service'))->toBe('ready');
});

test('provider and DI cache paths preserve the same config v3 runtime environment semantics', function (): void {
    $environment = new Environment(['APP_TOKEN' => 'runtime-token']);
    $application = ['token' => env('APP_TOKEN')];
    $dependencies = [
        ConfigKey::SERVICES => ['configured.service' => 'ready'],
    ];

    $providerContainer = ContainerBuilder::configure(new Config([
        ...$application,
        ConfigKey::DEPENDENCIES => $dependencies,
    ], $environment))->build();

    $cache = [
        'version' => ContainerBuilder::CACHE_VERSION,
        ConfigKey::DEPENDENCIES => ContainerBuilder::normalizeDependencies($dependencies),
    ];
    $cachedContainer = ContainerBuilder::configureFromCache(
        new Config($application, $environment),
        $cache,
    )->build();

    foreach ([$providerContainer, $cachedContainer] as $container) {
        $runtimeConfig = $container->get(Config::class);
        expect($runtimeConfig)->toBeInstanceOf(Config::class)
            ->and($runtimeConfig->environment)->toBe($environment)
            ->and($runtimeConfig->string('token'))->toBe('runtime-token')
            ->and($container->get(Environment::class))->toBe($environment)
            ->and($container->get('configured.service'))->toBe('ready');
    }
});

test('integer application keys survive DI normalization and cache bootstrap without reindexing', function (): void {
    $environment = new Environment([]);
    $application = [
        404 => 'not-found',
        500 => 'server-error',
    ];
    $dependencies = [
        ConfigKey::SERVICES => ['configured.service' => 'ready'],
    ];

    $providerContainer = ContainerBuilder::configure(new Config([
        404 => 'not-found',
        500 => 'server-error',
        ConfigKey::DEPENDENCIES => $dependencies,
    ], $environment))->build();

    $cachedContainer = ContainerBuilder::configureFromCache(
        new Config($application, $environment),
        [
            'version' => ContainerBuilder::CACHE_VERSION,
            ConfigKey::DEPENDENCIES => ContainerBuilder::normalizeDependencies($dependencies),
        ],
    )->build();

    foreach ([$providerContainer, $cachedContainer] as $container) {
        $runtimeConfig = $container->get(Config::class);

        expect($runtimeConfig)->toBeInstanceOf(Config::class)
            ->and($runtimeConfig->get(404))->toBe('not-found')
            ->and($runtimeConfig->get(500))->toBe('server-error')
            ->and($runtimeConfig->has(0))->toBeFalse()
            ->and($runtimeConfig->environment)->toBe($environment);
    }
});

test('DI config key facade exactly follows config v3 schema', function (): void {
    expect(ConfigKey::dependencyKeys())->toBe(BaseConfigKey::dependencyKeys())
        ->and(ConfigKey::ATTRIBUTE_DEFINITIONS)->toBe(BaseConfigKey::ATTRIBUTE_DEFINITIONS)
        ->and(ConfigKey::ATTRIBUTE_DEFINITIONS_REPLACE)->toBe(BaseConfigKey::ATTRIBUTE_DEFINITIONS_REPLACE)
        ->and(ConfigKey::ATTRIBUTE_CAPABILITIES)->toBe(BaseConfigKey::ATTRIBUTE_CAPABILITIES);
});

<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\Config\Config;
use Componenta\Config\ConfigEntry;
use Componenta\Config\ConfigKey as BaseConfigKey;
use Componenta\Config\ContainerValue;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;

use function Componenta\Config\env;

#[SetUp('captureDependencies', [
    'dependencies' => new ConfigEntry(ConfigKey::DEPENDENCIES),
])]
final class RuntimeConfigSetUpProbe
{
    /** @var array<string,mixed> */
    public array $dependencies = [];

    /** @param array<string,mixed> $dependencies */
    public function captureDependencies(array $dependencies): void
    {
        $this->dependencies = $dependencies;
    }
}

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

test('runtime config is finalized from fluent builder state in provider and cache modes', function (): void {
    $environment = new Environment(['APP_TOKEN' => 'runtime-token']);
    $application = [
        'app.name' => 'componenta',
        'token' => env('APP_TOKEN'),
    ];
    $dependencies = [
        ConfigKey::SERVICES => [
            'configured.service' => 'ready',
        ],
    ];

    $builders = [
        ContainerBuilder::configure(new Config([
            ...$application,
            ConfigKey::DEPENDENCIES => $dependencies,
        ], $environment)),
        ContainerBuilder::configureFromCache(
            new Config($application, $environment),
            [
                'version' => ContainerBuilder::CACHE_VERSION,
                ConfigKey::DEPENDENCIES => ContainerBuilder::normalizeDependencies($dependencies),
            ],
        ),
    ];

    foreach ($builders as $builder) {
        $builder
            ->addService('late.service', 'late')
            ->addAlias('late.alias', 'late.service');

        $expectedDependencies = $builder->toArray()[ConfigKey::DEPENDENCIES];
        $container = $builder->build();
        $runtimeConfig = $container->get(Config::class);
        $containerValue = $container->get(ContainerValue::class);
        $setUpProbe = $container->make(RuntimeConfigSetUpProbe::class);

        expect($runtimeConfig)->toBeInstanceOf(Config::class)
            ->and($builder->config)->toBe($runtimeConfig)
            ->and($runtimeConfig->environment)->toBe($environment)
            ->and($runtimeConfig->string('app.name'))->toBe('componenta')
            ->and($runtimeConfig->string('token'))->toBe('runtime-token')
            ->and($runtimeConfig->get(ConfigKey::DEPENDENCIES))->toBe($expectedDependencies)
            ->and($containerValue)->toBeInstanceOf(ContainerValue::class)
            ->and($containerValue->config)->toBe($runtimeConfig)
            ->and($setUpProbe->dependencies)->toBe($expectedDependencies)
            ->and($container->get('late.alias'))->toBe('late');
    }
});

test('standalone builder publishes its effective dependency graph through runtime config', function (): void {
    $builder = (new ContainerBuilder())
        ->addService('standalone.service', 'ready');
    $expectedDependencies = $builder->toArray()[ConfigKey::DEPENDENCIES];

    $container = $builder->build();
    $runtimeConfig = $container->get(Config::class);

    expect($runtimeConfig)->toBeInstanceOf(Config::class)
        ->and($builder->config)->toBe($runtimeConfig)
        ->and($runtimeConfig->get(ConfigKey::DEPENDENCIES))->toBe($expectedDependencies)
        ->and($container->get(ContainerValue::class)->config)->toBe($runtimeConfig)
        ->and($container->get('standalone.service'))->toBe('ready');
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

test('DI config key inherits the shared config v3 schema', function (): void {
    expect(is_subclass_of(ConfigKey::class, BaseConfigKey::class))->toBeTrue()
        ->and(ConfigKey::dependencyKeys())->toBe(BaseConfigKey::dependencyKeys())
        ->and(ConfigKey::ATTRIBUTE_DEFINITIONS)->toBe(BaseConfigKey::ATTRIBUTE_DEFINITIONS)
        ->and(ConfigKey::ATTRIBUTE_DEFINITIONS_REPLACE)->toBe(BaseConfigKey::ATTRIBUTE_DEFINITIONS_REPLACE)
        ->and(ConfigKey::ATTRIBUTE_CAPABILITIES)->toBe(BaseConfigKey::ATTRIBUTE_CAPABILITIES);
});

<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\Config\Config;
use Componenta\Config\ConfigKey;
use Componenta\Config\ContainerValue;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\NotFoundException;

test('container factory preserves the configuration boundary by identity', function (): void {
    $environment = new Environment(['APP_ENV' => 'production']);
    $config = new Config(['app.name' => 'componenta'], $environment);
    $dependencies = new DependencyDefinitions([
        ConfigKey::SERVICES => [
            'runtime.service' => new \stdClass(),
        ],
    ]);

    $value = (new ContainerFactory())->create($config, $dependencies);
    $services = $dependencies->sections[ConfigKey::SERVICES] ?? null;

    if (!$value->container instanceof Container || !is_array($services)) {
        throw new \LogicException('The container factory contract fixture is invalid.');
    }

    expect($value)->toBeInstanceOf(ContainerValue::class)
        ->and($value->container)->toBeInstanceOf(Container::class)
        ->and($value->config)->toBe($config)
        ->and($value->container->get(Config::class))->toBe($config)
        ->and($value->container->get(Environment::class))->toBe($environment)
        ->and($value->container->get('runtime.service'))
        ->toBe($services['runtime.service'])
        ->and($config->has(ConfigKey::DEPENDENCIES))->toBeFalse();
});

test('dependency definitions are construction input rather than a runtime service', function (): void {
    $value = (new ContainerFactory())->create(
        new Config([], new Environment([])),
        new DependencyDefinitions([]),
    );

    if (!$value->container instanceof Container) {
        throw new \LogicException('The container factory must return the Componenta container.');
    }
    $container = $value->container;

    expect($container->has(DependencyDefinitions::class))->toBeFalse()
        ->and(fn() => $container->get(DependencyDefinitions::class))
        ->toThrow(NotFoundException::class)
        ->and(fn() => $container->set(
            DependencyDefinitions::class,
            new DependencyDefinitions([]),
        ))
        ->toThrow(InvalidConfigurationException::class);
});

test('dependency definitions cannot be registered declaratively as a service', function (): void {
    expect(fn() => (new ContainerFactory())->create(
        new Config([], new Environment([])),
        new DependencyDefinitions([
            ConfigKey::SERVICES => [
                DependencyDefinitions::class => new DependencyDefinitions([]),
            ],
        ]),
    ))->toThrow(InvalidConfigurationException::class);
});

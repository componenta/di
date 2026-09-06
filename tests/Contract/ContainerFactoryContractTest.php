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
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Tests\Support\AutoloadFailureTarget;

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

it('normalizes autoload failures during container construction and preserves their cause', function (bool $classDefinition): void {
    $class = AutoloadFailureTarget::class;
    $cause = new \RuntimeException('Autoload failed.');
    $loader = static function (string $requested) use ($class, $cause): void {
        if ($requested === $class) {
            throw $cause;
        }
    };
    $dependencies = new DependencyDefinitions($classDefinition
        ? [ConfigKey::FACTORIES => ['target' => ClassDefinition::create($class)]]
        : [ConfigKey::INVOKABLES => [$class]]);
    spl_autoload_register($loader, prepend: true);

    $failure = null;
    try {
        (new ContainerFactory())->create(new Config([], new Environment([])), $dependencies);
    } catch (\Throwable $exception) {
        $failure = $exception;
    } finally {
        spl_autoload_unregister($loader);
    }

    expect($failure)->toBeInstanceOf(InvalidConfigurationException::class)
        ->and($failure?->getPrevious())->toBe($cause);
})->with(['ClassDefinition' => true, 'invokable' => false]);

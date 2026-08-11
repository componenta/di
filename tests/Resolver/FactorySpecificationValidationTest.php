<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\DI\Compile\Factory\CompiledFactoryDefinition;
use Componenta\DI\ConfigKey;
use Componenta\DI\Container;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Definition\Definition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Tests\Fixture\SimpleService;

it('rejects malformed factory values during container assembly', function (): void {
    $config = new Config([
        ConfigKey::DEPENDENCIES => [
            ConfigKey::FACTORIES => [
                'invalid.factory' => 123,
            ],
        ],
    ]);

    expect(fn() => ContainerBuilder::configure($config)->build())
        ->toThrow(InvalidConfigurationException::class, 'Factory "invalid.factory"');
});

it('rejects a concrete object factory method that does not exist', function (): void {
    $factory = new class () {};
    $config = new Config([
        ConfigKey::DEPENDENCIES => [
            ConfigKey::FACTORIES => [
                'invalid.object.factory' => [$factory, 'missing'],
            ],
        ],
    ]);

    expect(fn() => ContainerBuilder::configure($config)->build())
        ->toThrow(InvalidConfigurationException::class, 'Factory "invalid.object.factory"');
});

it('accepts a deferred service method factory specification', function (): void {
    $config = new Config([
        ConfigKey::DEPENDENCIES => [
            ConfigKey::FACTORIES => [
                'deferred.factory' => ['factory.service', 'create'],
            ],
        ],
    ]);

    expect(ContainerBuilder::configure($config)->build())
        ->toBeInstanceOf(Container::class);
});

it('accepts a class-shaped service id whose runtime object may add the factory method', function (): void {
    $config = new Config([
        ConfigKey::DEPENDENCIES => [
            ConfigKey::FACTORIES => [
                'deferred.class-shaped.factory' => [DateTimeInterface::class, 'create'],
            ],
        ],
    ]);

    expect(ContainerBuilder::configure($config)->build())
        ->toBeInstanceOf(Container::class);
});

it('rejects a non-instantiable ClassDefinition when it is registered', function (): void {
    $container = (new ContainerBuilder())->build();
    $definition = Definition::autowire(DateTimeInterface::class);

    expect(fn() => $container->set('invalid.class.definition', $definition))
        ->toThrow(
            InvalidConfigurationException::class,
            'targets non-instantiable class',
        );
});

it('rejects a missing ClassDefinition method when it is registered', function (): void {
    $container = (new ContainerBuilder())->build();
    $definition = Definition::autowire(SimpleService::class)
        ->method('missingMethod');

    expect(fn() => $container->set('invalid.class.method', $definition))
        ->toThrow(
            InvalidConfigurationException::class,
            'calls missing method',
        );
});

it('rejects an incomplete compiled definition registered at runtime', function (): void {
    $container = (new ContainerBuilder())->build();

    expect(fn() => $container->set(
        'invalid.compiled',
        new CompiledFactoryDefinition('', '', ''),
    ))->toThrow(InvalidConfigurationException::class);
});

<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\DI\Compile\Factory\CompiledFactoryDefinition;
use Componenta\DI\ConfigKey;
use Componenta\DI\Container;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\InvalidConfigurationException;

final class KnownFactoryClass
{
    public function create(): object
    {
        return new stdClass();
    }
}

it('rejects malformed factory values during container assembly', function (): void {
    $config = new Config([
        ConfigKey::DEPENDENCIES => [
            ConfigKey::FACTORIES => [
                'invalid.factory' => 123,
            ],
        ],
    ]);

    expect(fn () => ContainerBuilder::configure($config)->build())
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

    expect(fn () => ContainerBuilder::configure($config)->build())
        ->toThrow(InvalidConfigurationException::class, 'Factory "invalid.object.factory"');
});

it('rejects a missing method on a known factory class', function (): void {
    $config = new Config([
        ConfigKey::DEPENDENCIES => [
            ConfigKey::FACTORIES => [
                'invalid.class.factory' => [KnownFactoryClass::class, 'missing'],
            ],
        ],
    ]);

    expect(fn () => ContainerBuilder::configure($config)->build())
        ->toThrow(InvalidConfigurationException::class, 'Factory "invalid.class.factory"');
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

it('rejects an incomplete compiled definition registered at runtime', function (): void {
    $container = (new ContainerBuilder())->build();

    expect(fn () => $container->set(
        'invalid.compiled',
        new CompiledFactoryDefinition('', '', ''),
    ))->toThrow(InvalidConfigurationException::class);
});

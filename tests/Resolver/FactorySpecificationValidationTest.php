<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\DI\ConfigKey;
use Componenta\DI\Container;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\InvalidConfigurationException;

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

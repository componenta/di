<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Resolver\Parameter\ParametersResolver;

it('rejects protected aliases loaded from persistent cache', function (): void {
    expect(fn() => ContainerBuilder::configureFromCache(
        new Config([]),
        [
            'version' => ContainerBuilder::CACHE_VERSION,
            ConfigKey::DEPENDENCIES => [
                ConfigKey::ALIASES => [
                    ParametersResolver::class => 'cached.fake-parameters',
                ],
            ],
        ],
    )->build())->toThrow(InvalidConfigurationException::class);
});

it('rejects conflicting bindings loaded from persistent cache', function (): void {
    expect(fn() => ContainerBuilder::configureFromCache(
        new Config([]),
        [
            'version' => ContainerBuilder::CACHE_VERSION,
            ConfigKey::DEPENDENCIES => [
                ConfigKey::FACTORIES => [
                    'cached.conflict' => static fn() => new stdClass(),
                ],
                ConfigKey::SERVICES => [
                    'cached.conflict' => new stdClass(),
                ],
            ],
        ],
    )->build())->toThrow(InvalidConfigurationException::class);
});

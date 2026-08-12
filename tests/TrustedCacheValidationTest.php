<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Resolver\Parameter\ParametersResolver;

it('does not let a cache validation marker bypass protected alias validation', function (): void {
    expect(fn() => ContainerBuilder::configureFromCache(
        new Config([]),
        [
            'version' => ContainerBuilder::CACHE_VERSION,
            ContainerBuilder::CACHE_VALIDATED_KEY => true,
            ConfigKey::DEPENDENCIES => [
                ConfigKey::ALIASES => [
                    ParametersResolver::class => 'cached.fake-parameters',
                ],
            ],
        ],
    )->build())->toThrow(InvalidConfigurationException::class);
});

it('does not let a cache validation marker bypass conflicting binding validation', function (): void {
    expect(fn() => ContainerBuilder::configureFromCache(
        new Config([]),
        [
            'version' => ContainerBuilder::CACHE_VERSION,
            ContainerBuilder::CACHE_VALIDATED_KEY => true,
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

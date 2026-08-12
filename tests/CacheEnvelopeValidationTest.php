<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\InvalidConfigurationException;

it('rejects unknown keys in a versioned cache envelope', function (): void {
    expect(fn() => ContainerBuilder::configureFromCache(
        new Config([]),
        [
            'version' => ContainerBuilder::CACHE_VERSION,
            ConfigKey::DEPENDENCIES => [],
            'dependencis' => [],
        ],
    ))->toThrow(InvalidConfigurationException::class);
});

it('rejects a cache envelope with dependencies but no version', function (): void {
    expect(fn() => ContainerBuilder::configureFromCache(
        new Config([]),
        [ConfigKey::DEPENDENCIES => []],
    ))->toThrow(InvalidConfigurationException::class);
});

it('rejects a malformed cache validation marker', function (): void {
    expect(fn() => ContainerBuilder::configureFromCache(
        new Config([]),
        [
            'version' => ContainerBuilder::CACHE_VERSION,
            ContainerBuilder::CACHE_VALIDATED_KEY => 'yes',
            ConfigKey::DEPENDENCIES => [],
        ],
    ))->toThrow(InvalidConfigurationException::class);
});

it('keeps accepting a raw dependency array', function (): void {
    $builder = ContainerBuilder::configureFromCache(
        new Config([]),
        [ConfigKey::SERVICES => ['cache.raw' => 42]],
    );

    expect($builder->build()->get('cache.raw'))->toBe(42);
});

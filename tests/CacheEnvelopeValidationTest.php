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

it('rejects the removed validated cache marker', function (): void {
    expect(fn() => ContainerBuilder::configureFromCache(
        new Config([]),
        [
            'version' => ContainerBuilder::CACHE_VERSION,
            'validated' => true,
            ConfigKey::DEPENDENCIES => [],
        ],
    ))->toThrow(InvalidConfigurationException::class, 'validated');
});

it('rejects the removed raw dependency cache format', function (): void {
    expect(fn() => ContainerBuilder::configureFromCache(
        new Config([]),
        [ConfigKey::SERVICES => ['cache.raw' => 42]],
    ))->toThrow(InvalidConfigurationException::class);
});

it('accepts the versioned persistent cache envelope', function (): void {
    $builder = ContainerBuilder::configureFromCache(
        new Config([]),
        [
            'version' => ContainerBuilder::CACHE_VERSION,
            ConfigKey::DEPENDENCIES => [
                ConfigKey::SERVICES => ['cache.versioned' => 42],
            ],
        ],
    );

    expect($builder->build()->get('cache.versioned'))->toBe(42);
});

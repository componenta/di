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

it('rejects v9 caches that predate mapped request provenance guards', function (): void {
    expect(fn() => ContainerBuilder::configureFromCache(
        new Config([]),
        [
            'version' => 9,
            ConfigKey::DEPENDENCIES => [],
        ],
    ))->toThrow(InvalidConfigurationException::class, 'expected "10"');
});

it('accepts the deprecated validated producer marker without trusting it', function (): void {
    $builder = ContainerBuilder::configureFromCache(
        new Config([]),
        [
            'version' => ContainerBuilder::CACHE_VERSION,
            ContainerBuilder::CACHE_VALIDATED_KEY => true,
            ConfigKey::DEPENDENCIES => [
                ConfigKey::SERVICES => ['cache.compatible' => 42],
            ],
        ],
    );

    expect($builder->build()->get('cache.compatible'))->toBe(42);

    expect(fn() => ContainerBuilder::configureFromCache(
        new Config([]),
        [
            'version' => ContainerBuilder::CACHE_VERSION,
            ContainerBuilder::CACHE_VALIDATED_KEY => true,
            ConfigKey::DEPENDENCIES => [
                ConfigKey::FACTORIES => [
                    'cache.conflict' => static fn(): string => 'factory',
                ],
                ConfigKey::SERVICES => ['cache.conflict' => 'service'],
            ],
        ],
    ))->toThrow(InvalidConfigurationException::class, 'conflicting');

    expect(fn() => ContainerBuilder::configureFromCache(
        new Config([]),
        [
            'version' => ContainerBuilder::CACHE_VERSION,
            ContainerBuilder::CACHE_VALIDATED_KEY => false,
            ConfigKey::DEPENDENCIES => [],
        ],
    ))->toThrow(InvalidConfigurationException::class, 'must be true');
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

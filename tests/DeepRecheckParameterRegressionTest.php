<?php

declare(strict_types=1);

require_once __DIR__ . '/Fixture/container_helpers.php';

use Componenta\Config\Config;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Tests\Fixture\DelegatorContractImplementation;
use Componenta\DI\Tests\Fixture\RepeatedTypedConstructor;

test('explicit positional objects are not reused for earlier parameters by type', function () {
    $explicitSecond = new DelegatorContractImplementation();
    $container = minimalBuilder()->build();

    [$first, $second] = $container->call(
        static fn(
            DelegatorContractImplementation $first,
            DelegatorContractImplementation $second,
        ): array => [$first, $second],
        [1 => $explicitSecond],
    );

    expect($first)
        ->toBeInstanceOf(DelegatorContractImplementation::class)
        ->not->toBe($explicitSecond)
        ->and($second)->toBe($explicitSecond);
});

test('compiled and reflection factories keep repeated typed positional parameters isolated', function () {
    $directory = sys_get_temp_dir() . '/componenta-deep-recheck-' . bin2hex(random_bytes(5));
    $explicitSecond = new DelegatorContractImplementation();

    try {
        $reflection = minimalBuilder()->build();
        $factories = minimalBuilder()->compileFactories([
            RepeatedTypedConstructor::class,
        ], $directory);
        $compiled = ContainerBuilder::configureFromCache(
            new Config([]),
            [
                'version' => ContainerBuilder::CACHE_VERSION,
                ConfigKey::DEPENDENCIES => [ConfigKey::FACTORIES => $factories],
            ],
            $directory,
        )->build();

        $expected = $reflection->make(RepeatedTypedConstructor::class, [1 => $explicitSecond]);
        $actual = $compiled->make(RepeatedTypedConstructor::class, [1 => $explicitSecond]);

        expect($expected->first)->not->toBe($explicitSecond)
            ->and($expected->second)->toBe($explicitSecond)
            ->and($actual->first)->not->toBe($explicitSecond)
            ->and($actual->second)->toBe($explicitSecond);
    } finally {
        foreach (glob($directory . '/container.factories.*.php') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
});

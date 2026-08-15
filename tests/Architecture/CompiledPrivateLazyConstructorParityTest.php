<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Tests\Fixture\PrivateLazyWithDependency;
use Componenta\DI\Tests\Fixture\SimpleService;

it('keeps non-public lazy constructors with dependencies equal to reflection', function (): void {
    $directory = sys_get_temp_dir() . '/componenta-private-lazy-parity-' . bin2hex(random_bytes(5));

    try {
        $reflection = (new ContainerBuilder())->build();
        $factories = (new ContainerBuilder())->compileFactories([
            PrivateLazyWithDependency::class,
            SimpleService::class,
        ], $directory);
        $compiled = ContainerBuilder::configureFromCache(
            new Config([]),
            [
                'version' => ContainerBuilder::CACHE_VERSION,
                ConfigKey::DEPENDENCIES => [ConfigKey::FACTORIES => $factories],
            ],
            $directory,
        )->build();

        $expected = $reflection->make(PrivateLazyWithDependency::class);
        $actual = $compiled->make(PrivateLazyWithDependency::class);

        expect($expected->initialized)->toBeTrue()
            ->and($expected->dependency)->toBeInstanceOf(SimpleService::class)
            ->and($actual->initialized)->toBeTrue()
            ->and($actual->dependency)->toBeInstanceOf(SimpleService::class);
    } finally {
        foreach (glob($directory . '/container.factories.*.php') ?: [] as $file) {
            @unlink($file);
        }

        if (is_dir($directory)) {
            @rmdir($directory);
        }
    }
});

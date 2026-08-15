<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Tests\Fixture\PrivateInjectedChild;
use Componenta\DI\Tests\Fixture\PrivateInjectedDependency;

test('compiled factories include and resolve dependencies injected into private parent properties', function () {
    $directory = sys_get_temp_dir() . '/componenta-private-inject-' . bin2hex(random_bytes(5));

    try {
        $compiled = (new ContainerBuilder())->compileFactories([
            PrivateInjectedChild::class,
        ], $directory);

        expect($compiled)
            ->toHaveKey(PrivateInjectedChild::class)
            ->toHaveKey(PrivateInjectedDependency::class);

        $container = ContainerBuilder::configureFromCache(
            new Config([]),
            [
                'version' => ContainerBuilder::CACHE_VERSION,
                ConfigKey::DEPENDENCIES => [
                    ConfigKey::FACTORIES => $compiled,
                ],
            ],
            $directory,
        )->build();

        expect($container->make(PrivateInjectedChild::class)->dependency())
            ->toBeInstanceOf(PrivateInjectedDependency::class);
    } finally {
        foreach (glob($directory . '/container.factories.*.php') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
});

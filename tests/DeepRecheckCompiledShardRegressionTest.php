<?php

declare(strict_types=1);

require_once __DIR__ . '/Fixture/container_helpers.php';

use Componenta\Config\Config;
use Componenta\DI\Compile\Factory\CompiledFactoryDefinition;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Tests\Fixture\SimpleService;

test('compiled shard cache never reuses a shard for a conflicting declared class', function () {
    $directory = sys_get_temp_dir() . '/componenta-di-shard-' . bin2hex(random_bytes(5));

    try {
        $definition = minimalBuilder()->compileFactories([SimpleService::class], $directory)[SimpleService::class];
        $container = ContainerBuilder::configureFromCache(
            new Config([]),
            [
                'version' => ContainerBuilder::CACHE_VERSION,
                ConfigKey::DEPENDENCIES => [
                    ConfigKey::FACTORIES => [
                        'first' => $definition,
                        'second' => new CompiledFactoryDefinition(
                            $definition->file,
                            $definition->class . 'Mismatch',
                            $definition->method,
                        ),
                    ],
                ],
            ],
            $directory,
        )->build();

        expect($container->make('first'))->toBeInstanceOf(SimpleService::class)
            ->and(fn() => $container->make('second'))
            ->toThrow(InvalidConfigurationException::class);
    } finally {
        foreach (glob($directory . '/container.factories.*.php') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
});

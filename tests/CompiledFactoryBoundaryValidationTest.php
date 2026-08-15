<?php

declare(strict_types=1);

use Componenta\DI\Compile\Factory\CompiledFactoryDefinition;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\InvalidConfigurationException;

it('rejects compiled factory definitions whose file contains the cache separator', function (): void {
    $definition = new CompiledFactoryDefinition(
        "bad\0path",
        'GeneratedFactoryForBoundaryTest',
        'createService',
    );

    expect(fn() => (new ContainerBuilder())->build()->set('service', $definition))
        ->toThrow(InvalidConfigurationException::class);
});

it('rejects compiled factory definitions with an invalid method name at registration time', function (): void {
    $definition = new CompiledFactoryDefinition(
        '/tmp/generated-factory.php',
        'GeneratedFactoryForBoundaryTest',
        "create\0Service",
    );

    expect(fn() => (new ContainerBuilder())->build()->set('service', $definition))
        ->toThrow(InvalidConfigurationException::class);
});

it('rejects drive-qualified compiled factory paths at the cache boundary', function (): void {
    $directory = sys_get_temp_dir() . '/componenta-di-drive-relative-' . bin2hex(random_bytes(5));
    mkdir($directory, 0775, true);
    try {
        $container = ContainerBuilder::configureFromCache(
            new \Componenta\Config\Config([]),
            [
                'version' => ContainerBuilder::CACHE_VERSION,
                \Componenta\DI\ConfigKey::DEPENDENCIES => [
                    \Componenta\DI\ConfigKey::FACTORIES => [
                        'drive.relative' => new CompiledFactoryDefinition(
                            'C:factory.php',
                            stdClass::class,
                            'create',
                        ),
                    ],
                ],
            ],
            $directory,
        )->build();

        expect(fn() => $container->get('drive.relative'))
            ->toThrow(InvalidConfigurationException::class, 'relative path');
    } finally {
        @rmdir($directory);
    }
});

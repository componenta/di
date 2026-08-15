<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\DI\Compile\Factory\CompiledFactoryDefinition;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\InvalidConfigurationException;

function pathBoundaryCache(CompiledFactoryDefinition $definition): array
{
    return [
        'version' => ContainerBuilder::CACHE_VERSION,
        ConfigKey::DEPENDENCIES => [
            ConfigKey::FACTORIES => ['entry' => $definition],
        ],
    ];
}

it('rejects a compiled shard traversal outside the configured cache base directory', function (): void {
    $root = sys_get_temp_dir() . '/componenta-path-boundary-' . bin2hex(random_bytes(5));
    $base = $root . '/base';
    $outside = $root . '/outside.php';
    mkdir($base, 0777, true);
    file_put_contents($outside, '<?php throw new RuntimeException("must not execute");');

    try {
        $container = ContainerBuilder::configureFromCache(
            new Config([]),
            pathBoundaryCache(new CompiledFactoryDefinition(
                '../outside.php',
                stdClass::class,
                'create',
            )),
            $base,
        )->build();

        expect(fn() => $container->get('entry'))
            ->toThrow(InvalidConfigurationException::class, 'outside base directory');
    } finally {
        @unlink($outside);
        @rmdir($base);
        @rmdir($root);
    }
});

it('requires an explicit base directory for compiled factories loaded from cache', function (): void {
    $container = ContainerBuilder::configureFromCache(
        new Config([]),
        pathBoundaryCache(new CompiledFactoryDefinition(
            'factory.php',
            stdClass::class,
            'create',
        )),
    )->build();

    expect(fn() => $container->get('entry'))
        ->toThrow(InvalidConfigurationException::class, 'require a base directory');
});

it('rejects a compiled shard symlink that escapes the configured cache base directory', function (): void {
    if (DIRECTORY_SEPARATOR === '\\') {
        $this->markTestSkipped('Creating symlinks requires an explicit Windows privilege.');
    }

    $root = sys_get_temp_dir() . '/componenta-path-symlink-' . bin2hex(random_bytes(5));
    $base = $root . '/base';
    $outside = $root . '/outside.php';
    $link = $base . '/link.php';
    mkdir($base, 0777, true);
    file_put_contents($outside, '<?php throw new RuntimeException("must not execute");');
    $created = @symlink($outside, $link);

    if (!$created) {
        @unlink($outside);
        @rmdir($base);
        @rmdir($root);
        $this->markTestSkipped('Symlinks are unavailable in this environment.');
    }

    try {
        $container = ContainerBuilder::configureFromCache(
            new Config([]),
            pathBoundaryCache(new CompiledFactoryDefinition(
                'link.php',
                stdClass::class,
                'create',
            )),
            $base,
        )->build();

        expect(fn() => $container->get('entry'))
            ->toThrow(InvalidConfigurationException::class, 'outside base directory');
    } finally {
        @unlink($link);
        @unlink($outside);
        @rmdir($base);
        @rmdir($root);
    }
});

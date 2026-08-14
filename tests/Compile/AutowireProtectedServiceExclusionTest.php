<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\DI\Compile\Factory\CompiledFactoryShardCompiler;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;

final readonly class ProtectedAotServiceRoot
{
    public function __construct(public Config $config) {}
}

it('never compiles container-owned bootstrap services discovered in an AOT graph', function (): void {
    $directory = sys_get_temp_dir() . '/componenta-protected-aot-' . bin2hex(random_bytes(5));

    try {
        $factories = (new ContainerBuilder())->compileFactories(
            [ProtectedAotServiceRoot::class],
            $directory,
            maxShardBytes: 1,
            namespace: 'Componenta\\DI\\Tests\\ProtectedAotGenerated',
        );

        expect($factories)
            ->toHaveKey(ProtectedAotServiceRoot::class)
            ->not->toHaveKey(Config::class);

        $container = ContainerBuilder::configureFromCache(
            new Config(['marker' => 'runtime']),
            [
                'version' => ContainerBuilder::CACHE_VERSION,
                ConfigKey::DEPENDENCIES => [
                    ConfigKey::FACTORIES => $factories,
                ],
            ],
            $directory,
        )->build();
        $root = $container->make(ProtectedAotServiceRoot::class);

        expect($root->config)->toBe($container->get(Config::class))
            ->and($root->config->get('marker'))->toBe('runtime');
    } finally {
        foreach (glob($directory . '/' . CompiledFactoryShardCompiler::FILE_PREFIX . '*.php') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
});

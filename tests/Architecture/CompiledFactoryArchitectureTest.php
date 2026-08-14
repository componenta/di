<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\DI\Attribute\Inject;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\Compile\Factory\CompiledFactoryDefinition;
use Componenta\DI\Compile\Factory\CompiledFactoryShardCompiler;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;

final readonly class CompiledGraphLeafForTest {}

#[SetUp('initialize')]
final class CompiledGraphSetUpForTest
{
    public function initialize(CompiledGraphLeafForTest $leaf): void {}
}

final class CompiledGraphRootForTest
{
    #[Inject]
    private CompiledGraphSetUpForTest $setup;

    public function __construct(public CompiledGraphLeafForTest $leaf) {}
}

final readonly class CompiledFactoryLeafForTest {}

final readonly class CompiledFactoryRootForTest
{
    public function __construct(
        public CompiledFactoryLeafForTest $leaf,
        public int $value = 1,
    ) {}
}

it('compiles statically knowable dependencies while explicit bindings retain ownership', function (): void {
    $directory = sys_get_temp_dir() . '/componenta-compile-graph-' . bin2hex(random_bytes(5));
    $explicitDirectory = sys_get_temp_dir() . '/componenta-compile-graph-explicit-' . bin2hex(random_bytes(5));

    try {
        $compiled = (new ContainerBuilder())->compileFactories(
            [CompiledGraphRootForTest::class],
            $directory,
        );

        expect($compiled)->toHaveKeys([
            CompiledGraphLeafForTest::class,
            CompiledGraphRootForTest::class,
            CompiledGraphSetUpForTest::class,
        ]);

        $withExplicitLeaf = (new ContainerBuilder())
            ->addService(CompiledGraphLeafForTest::class, new CompiledGraphLeafForTest())
            ->compileFactories(
                [CompiledGraphRootForTest::class],
                $explicitDirectory,
            );

        expect($withExplicitLeaf)
            ->not->toHaveKey(CompiledGraphLeafForTest::class)
            ->toHaveKey(CompiledGraphRootForTest::class)
            ->toHaveKey(CompiledGraphSetUpForTest::class);
    } finally {
        foreach ([$directory, $explicitDirectory] as $root) {
            foreach (glob($root . '/' . CompiledFactoryShardCompiler::FILE_PREFIX . '*.php') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($root);
        }
    }
});

it('returns a loadable compiled graph as regular factory definitions', function (): void {
    $directory = sys_get_temp_dir() . '/componenta-factory-shards-' . bin2hex(random_bytes(5));

    try {
        $builder = new ContainerBuilder();
        $factories = $builder->compileFactories(
            [CompiledFactoryRootForTest::class],
            $directory,
            maxShardBytes: 1,
            namespace: 'Componenta\\DI\\Tests\\Generated',
        );

        expect($factories)->toHaveKeys([
            CompiledFactoryLeafForTest::class,
            CompiledFactoryRootForTest::class,
        ])->and($builder->invokables)->toBe([]);

        foreach ($factories as $factory) {
            expect($factory)->toBeInstanceOf(CompiledFactoryDefinition::class)
                ->and(is_file($directory . '/' . $factory->file))->toBeTrue();
        }

        $container = ContainerBuilder::configureFromCache(
            new Config([]),
            [
                'version' => ContainerBuilder::CACHE_VERSION,
                ConfigKey::DEPENDENCIES => [
                    ConfigKey::FACTORIES => $factories,
                ],
            ],
            $directory,
        )->build();
        $entry = $container->make(CompiledFactoryRootForTest::class, ['value' => 42]);

        expect($entry)->toBeInstanceOf(CompiledFactoryRootForTest::class)
            ->and($entry->leaf)->toBeInstanceOf(CompiledFactoryLeafForTest::class)
            ->and($entry->value)->toBe(42);

        $secondContainer = ContainerBuilder::configureFromCache(
            new Config([]),
            [
                'version' => ContainerBuilder::CACHE_VERSION,
                ConfigKey::DEPENDENCIES => [
                    ConfigKey::FACTORIES => $factories,
                ],
            ],
            $directory,
        )->build();

        expect($secondContainer->make(CompiledFactoryRootForTest::class))
            ->toBeInstanceOf(CompiledFactoryRootForTest::class);
    } finally {
        foreach (glob($directory . '/' . CompiledFactoryShardCompiler::FILE_PREFIX . '*.php') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
});

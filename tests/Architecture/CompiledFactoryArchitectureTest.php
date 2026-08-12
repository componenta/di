<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\DI\Attribute\Inject;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\Compile\Autowire\AutowireClassGraph;
use Componenta\DI\Compile\Autowire\AutowireEntry;
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

it('expands only statically knowable concrete dependencies and honours explicit bindings', function (): void {
    $graph = new AutowireClassGraph();

    expect($graph->expand([new AutowireEntry(CompiledGraphRootForTest::class)]))
        ->toBe([
            CompiledGraphLeafForTest::class,
            CompiledGraphRootForTest::class,
            CompiledGraphSetUpForTest::class,
        ])
        ->and($graph->expand(
            [CompiledGraphRootForTest::class],
            [CompiledGraphLeafForTest::class => true],
        ))->toBe([
            CompiledGraphRootForTest::class,
            CompiledGraphSetUpForTest::class,
        ]);
});

it('stores compiled autowiring as regular factories and loads shards lazily', function (): void {
    $directory = sys_get_temp_dir() . '/componenta-factory-shards-' . bin2hex(random_bytes(5));

    try {
        $builder = new ContainerBuilder();
        $factories = $builder->compileFactories(
            [CompiledFactoryRootForTest::class],
            $directory,
            maxShardBytes: 1,
            namespace: 'Componenta\\DI\\Tests\\Generated',
        );

        $definition = $factories[CompiledFactoryRootForTest::class];
        $source = file_get_contents($directory . '/' . $definition->file);

        expect($factories)->toHaveKeys([
            CompiledFactoryRootForTest::class,
        ])->not->toHaveKey(CompiledFactoryLeafForTest::class)
            ->and($builder->invokables)->toContain(CompiledFactoryLeafForTest::class)
            ->and(array_unique(array_map(
                static fn(CompiledFactoryDefinition $factory): string => $factory->file,
                $factories,
            )))->toHaveCount(1)
            ->and($source)->toBeString()->not->toContain('if (!class_exists(')
            ->and(strlen($definition->file))->toBe(strlen(CompiledFactoryShardCompiler::FILE_PREFIX) + 32 + 4)
            ->and(class_exists($definition->class, false))->toBeFalse();

        $container = ContainerBuilder::configureFromCache(
            new Config([]),
            [
                'version' => ContainerBuilder::CACHE_VERSION,
                ConfigKey::DEPENDENCIES => [
                    ConfigKey::FACTORIES => $factories,
                    ConfigKey::INVOKABLES => $builder->invokables,
                ],
            ],
            $directory,
        )->build();
        $entry = $container->make(CompiledFactoryRootForTest::class, ['value' => 42]);

        expect($entry)->toBeInstanceOf(CompiledFactoryRootForTest::class)
            ->and($entry->leaf)->toBeInstanceOf(CompiledFactoryLeafForTest::class)
            ->and($entry->value)->toBe(42)
            ->and(class_exists($definition->class, false))->toBeTrue();

        $secondContainer = ContainerBuilder::configureFromCache(
            new Config([]),
            [
                'version' => ContainerBuilder::CACHE_VERSION,
                ConfigKey::DEPENDENCIES => [
                    ConfigKey::FACTORIES => $factories,
                    ConfigKey::INVOKABLES => $builder->invokables,
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

it('does not expose the removed generated resolver contract', function (): void {
    expect(method_exists(ContainerBuilder::class, 'compileGeneratedEntryResolver'))->toBeFalse()
        ->and(method_exists(ContainerBuilder::class, 'useGeneratedEntryResolver'))->toBeFalse()
        ->and(defined(ConfigKey::class . '::GENERATED_ENTRY_RESOLVER_FILE'))->toBeFalse()
        ->and(class_exists('Componenta\\DI\\Compile\\Entry\\GeneratedEntryResolverLoader'))->toBeFalse();
});

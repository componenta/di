<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Componenta\DI\Cache\DiCacheGenerator;
use Componenta\DI\Compile\Factory\CompiledFactoryDefinition;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\Definition;
use Componenta\DI\Resolver\Entry\MappedRequestAwareFactory;

final readonly class DefinitionCacheDependency {}

final class DefinitionCacheConfiguredService
{
    public bool $initialized = false;

    public function __construct(
        public DefinitionCacheDependency $dependency,
        public string $name = 'default',
    ) {}

    public function initialize(DefinitionCacheDependency $dependency): void
    {
        $this->initialized = $dependency === $this->dependency;
    }
}

final readonly class DefinitionCacheFactoryProduct
{
    public function __construct(public string $name) {}
}

final class DefinitionCacheStaticFactory
{
    /** @param array<string|int, mixed> $context */
    public static function create(
        ContainerValue $container,
        array $context = [],
    ): DefinitionCacheFactoryProduct {
        return new DefinitionCacheFactoryProduct(
            $context['name'] ?? $container->config->get('definition-cache.name'),
        );
    }
}

final readonly class DefinitionCacheInvokable {}

final readonly class DefinitionCacheCompiledLeaf {}

final readonly class DefinitionCacheCompiledRoot
{
    public function __construct(
        public DefinitionCacheCompiledLeaf $leaf,
        public int $value = 1,
    ) {}
}

it('compiles resolver definitions to canonical persistent cache forms', function (): void {
    $root = sys_get_temp_dir() . '/componenta-definition-cache-' . bin2hex(random_bytes(5));
    $cacheFile = $root . '/container.php';

    try {
        (new DiCacheGenerator())->generate([
            ConfigKey::FACTORIES => [
                DefinitionCacheConfiguredService::class => ClassDefinition::create(
                    DefinitionCacheConfiguredService::class,
                )->constructor([
                    'dependency' => Definition::reference(DefinitionCacheDependency::class),
                    'name' => 'from-class-definition',
                ])->method('initialize', [
                    Definition::reference(DefinitionCacheDependency::class),
                ]),
                'definition-cache.factory' => Definition::factory([
                    DefinitionCacheStaticFactory::class,
                    'create',
                ]),
            ],
            ConfigKey::INVOKABLES => [
                DefinitionCacheDependency::class,
                'definition-cache.invokable' => Definition::invokable(
                    DefinitionCacheInvokable::class,
                ),
            ],
        ], $cacheFile);

        $cache = require $cacheFile;
        $dependencies = $cache[ConfigKey::DEPENDENCIES];

        expect($dependencies[ConfigKey::FACTORIES][DefinitionCacheConfiguredService::class])
            ->toBeInstanceOf(MappedRequestAwareFactory::class)
            ->and($dependencies[ConfigKey::ALIASES]['definition-cache.invokable'])
            ->toBe(DefinitionCacheInvokable::class)
            ->and($dependencies[ConfigKey::INVOKABLES])
            ->toContain(DefinitionCacheInvokable::class);

        $container = ContainerBuilder::configureFromCache(
            new Config(['definition-cache.name' => 'from-config']),
            $cache,
            $root,
        )->build();

        $configured = $container->make(DefinitionCacheConfiguredService::class);
        $factory = $container->make('definition-cache.factory', ['name' => 'from-context']);
        $invokable = $container->make('definition-cache.invokable');

        expect($configured->dependency)->toBeInstanceOf(DefinitionCacheDependency::class)
            ->and($configured->name)->toBe('from-class-definition')
            ->and($configured->initialized)->toBeTrue()
            ->and($factory)->toBeInstanceOf(DefinitionCacheFactoryProduct::class)
            ->and($factory->name)->toBe('from-context')
            ->and($invokable)->toBeInstanceOf(DefinitionCacheInvokable::class);
    } finally {
        @unlink($cacheFile);
        @rmdir($root);
    }
});

it('round-trips generated factories through the real persistent cache writer', function (): void {
    $root = sys_get_temp_dir() . '/componenta-compiled-cache-e2e-' . bin2hex(random_bytes(5));
    $shards = $root . '/shards';
    $cacheFile = $root . '/container.php';

    try {
        $compiled = (new ContainerBuilder())->compileFactories([
            DefinitionCacheCompiledRoot::class,
        ], $shards);

        (new DiCacheGenerator())->generate([
            ConfigKey::FACTORIES => $compiled,
        ], $cacheFile);

        $cache = require $cacheFile;
        expect($cache[ConfigKey::DEPENDENCIES][ConfigKey::FACTORIES][DefinitionCacheCompiledRoot::class])
            ->toBeInstanceOf(CompiledFactoryDefinition::class);

        $container = ContainerBuilder::configureFromCache(
            new Config([]),
            $cache,
            $shards,
        )->build();
        $entry = $container->make(DefinitionCacheCompiledRoot::class, ['value' => 42]);

        expect($entry)->toBeInstanceOf(DefinitionCacheCompiledRoot::class)
            ->and($entry->leaf)->toBeInstanceOf(DefinitionCacheCompiledLeaf::class)
            ->and($entry->value)->toBe(42);
    } finally {
        foreach (glob($shards . '/container.factories.*.php') ?: [] as $file) {
            @unlink($file);
        }
        @unlink($cacheFile);
        @rmdir($shards);
        @rmdir($root);
    }
});

it('keeps definition-configured bindings out of the autowire compilation graph', function (): void {
    $root = sys_get_temp_dir() . '/componenta-definition-compile-exclusion-' . bin2hex(random_bytes(5));

    try {
        $builder = ContainerBuilder::configure(new Config([
            ConfigKey::DEPENDENCIES => [
                ConfigKey::FACTORIES => [
                    DefinitionCacheConfiguredService::class => ClassDefinition::create(
                        DefinitionCacheConfiguredService::class,
                    )->constructor([
                        'dependency' => Definition::reference(DefinitionCacheDependency::class),
                    ]),
                ],
                ConfigKey::INVOKABLES => [
                    Definition::invokable(DefinitionCacheInvokable::class),
                ],
            ],
        ]));

        $compiled = $builder->compileFactories([
            DefinitionCacheConfiguredService::class,
            DefinitionCacheInvokable::class,
            DefinitionCacheCompiledRoot::class,
        ], $root);

        expect($compiled)
            ->not->toHaveKey(DefinitionCacheConfiguredService::class)
            ->not->toHaveKey(DefinitionCacheInvokable::class)
            ->toHaveKey(DefinitionCacheCompiledRoot::class)
            ->toHaveKey(DefinitionCacheCompiledLeaf::class);
    } finally {
        foreach (glob($root . '/container.factories.*.php') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($root);
    }
});

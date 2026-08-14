<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Cache;

use Closure;
use Componenta\Config\Config;
use Componenta\DI\Cache\DiCacheGenerator;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\Definition;

final readonly class CompiledClassDefinitionDependency {}

final class CompiledClassDefinitionTarget
{
    public ?CompiledClassDefinitionDependency $bootDependency = null;
    public ?string $bootLabel = null;

    public function __construct(
        public string $first = 'first-default',
        public ?CompiledClassDefinitionDependency $dependency = null,
    ) {}

    public function boot(
        CompiledClassDefinitionDependency $dependency,
        string $label,
    ): void {
        $this->bootDependency = $dependency;
        $this->bootLabel = $label;
    }
}

final class CompiledClassDefinitionNativeDefault
{
    public function __construct(public object $marker = new \stdClass()) {}
}

it('compiles configured ClassDefinition objects to closure factories in the persistent cache', function (): void {
    $root = sys_get_temp_dir() . '/componenta-class-definition-cache-' . bin2hex(random_bytes(5));
    $cacheFile = $root . '/container.php';

    try {
        (new DiCacheGenerator())->generate([
            ConfigKey::FACTORIES => [
                CompiledClassDefinitionTarget::class => ClassDefinition::create(
                    CompiledClassDefinitionTarget::class,
                )->constructor([
                    'first' => 'configured-first',
                    'dependency' => Definition::reference(
                        CompiledClassDefinitionDependency::class,
                    ),
                ])->method('boot', [
                    Definition::reference(CompiledClassDefinitionDependency::class),
                    'booted',
                ]),
                CompiledClassDefinitionNativeDefault::class => ClassDefinition::create(
                    CompiledClassDefinitionNativeDefault::class,
                ),
            ],
            ConfigKey::INVOKABLES => [CompiledClassDefinitionDependency::class],
        ], $cacheFile);

        $cache = require $cacheFile;
        $factories = $cache[ConfigKey::DEPENDENCIES][ConfigKey::FACTORIES];

        expect($factories[CompiledClassDefinitionTarget::class])
            ->toBeInstanceOf(Closure::class)
            ->and($factories[CompiledClassDefinitionNativeDefault::class])
            ->toBeInstanceOf(Closure::class);

        $container = ContainerBuilder::configureFromCache(
            new Config([]),
            $cache,
            $root,
        )->build();
        $runtimeDependency = new CompiledClassDefinitionDependency();
        $entry = $container->make(CompiledClassDefinitionTarget::class, [
            0 => 'runtime-first',
            CompiledClassDefinitionDependency::class => $runtimeDependency,
        ]);

        expect($entry->first)->toBe('runtime-first')
            ->and($entry->dependency)->toBe($runtimeDependency)
            ->and($entry->bootDependency)
            ->toBeInstanceOf(CompiledClassDefinitionDependency::class)
            ->and($entry->bootDependency)->not->toBe($runtimeDependency)
            ->and($entry->bootLabel)->toBe('booted');

        $firstDefault = $container->make(CompiledClassDefinitionNativeDefault::class);
        $secondDefault = $container->make(CompiledClassDefinitionNativeDefault::class);
        expect($firstDefault->marker)->not->toBe($secondDefault->marker);
    } finally {
        @unlink($cacheFile);
        @rmdir($root);
    }
});

it('does not feed runtime Container::set definitions back into builder compilation state', function (): void {
    $builder = ContainerBuilder::configure(new Config([
        ConfigKey::DEPENDENCIES => [
            ConfigKey::FACTORIES => [
                CompiledClassDefinitionTarget::class => ClassDefinition::create(
                    CompiledClassDefinitionTarget::class,
                ),
            ],
        ],
    ]));
    $container = $builder->build();
    $container->set(
        'runtime.class-definition',
        ClassDefinition::create(CompiledClassDefinitionNativeDefault::class),
    );

    $dependencies = $builder->toArray()[ConfigKey::DEPENDENCIES];

    expect($dependencies[ConfigKey::FACTORIES])
        ->toHaveKey(CompiledClassDefinitionTarget::class)
        ->not->toHaveKey('runtime.class-definition');
});

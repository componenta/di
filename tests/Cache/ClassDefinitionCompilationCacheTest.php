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

final readonly class CompiledClassDefinitionConfiguredMarker
{
    public function __construct(public string $value) {}
}

final class CompiledClassDefinitionConfiguredValueTarget
{
    public ?CompiledClassDefinitionConfiguredMarker $methodMarker = null;

    /** @param array{marker: CompiledClassDefinitionConfiguredMarker} $nested */
    public function __construct(
        public CompiledClassDefinitionConfiguredMarker $marker,
        public array $nested,
    ) {}

    public function configure(CompiledClassDefinitionConfiguredMarker $marker): void
    {
        $this->methodMarker = $marker;
    }
}

final class CompiledClassDefinitionConfiguredClosureTarget
{
    public function __construct(public Closure $callback) {}
}

final readonly class CompiledClassDefinitionLiveDependency
{
    public function __construct(public int $version) {}
}

final class CompiledClassDefinitionLiveReferenceTarget
{
    public function __construct(public object $dependency) {}
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

it('preserves configured object and closure identity while keeping references live after cache compilation', function (): void {
    $root = sys_get_temp_dir() . '/componenta-class-definition-identity-' . bin2hex(random_bytes(5));
    $cacheFile = $root . '/container.php';
    $configuredMarker = new CompiledClassDefinitionConfiguredMarker('configured');
    $configuredCallback = static fn(): string => 'configured-callback';
    $firstDependency = new CompiledClassDefinitionLiveDependency(1);

    try {
        (new DiCacheGenerator())->generate([
            ConfigKey::FACTORIES => [
                CompiledClassDefinitionConfiguredValueTarget::class => ClassDefinition::create(
                    CompiledClassDefinitionConfiguredValueTarget::class,
                )->constructor([
                    'marker' => $configuredMarker,
                    'nested' => ['marker' => $configuredMarker],
                ])->method('configure', [$configuredMarker]),
                CompiledClassDefinitionConfiguredClosureTarget::class => ClassDefinition::create(
                    CompiledClassDefinitionConfiguredClosureTarget::class,
                )->constructor([
                    'callback' => $configuredCallback,
                ]),
                CompiledClassDefinitionLiveReferenceTarget::class => ClassDefinition::create(
                    CompiledClassDefinitionLiveReferenceTarget::class,
                )->constructor([
                    'dependency' => Definition::reference('live.dependency'),
                ]),
            ],
            ConfigKey::SERVICES => [
                'live.dependency' => $firstDependency,
            ],
        ], $cacheFile);

        $container = ContainerBuilder::configureFromCache(
            new Config([]),
            require $cacheFile,
            $root,
        )->build();

        $first = $container->make(CompiledClassDefinitionConfiguredValueTarget::class);
        $second = $container->make(CompiledClassDefinitionConfiguredValueTarget::class);

        expect($first->marker)->toBe($first->nested['marker'])
            ->and($first->methodMarker)->toBe($first->marker)
            ->and($second->marker)->toBe($first->marker)
            ->and($second->nested['marker'])->toBe($first->marker)
            ->and($second->methodMarker)->toBe($first->marker);

        $firstClosure = $container->make(CompiledClassDefinitionConfiguredClosureTarget::class);
        $secondClosure = $container->make(CompiledClassDefinitionConfiguredClosureTarget::class);

        expect($firstClosure->callback)->toBe($secondClosure->callback)
            ->and(($firstClosure->callback)())->toBe('configured-callback');

        $firstReference = $container->make(CompiledClassDefinitionLiveReferenceTarget::class);
        $secondDependency = new CompiledClassDefinitionLiveDependency(2);
        $container->set('live.dependency', $secondDependency);
        $secondReference = $container->make(CompiledClassDefinitionLiveReferenceTarget::class);

        expect($firstReference->dependency)->not->toBe($secondReference->dependency)
            ->and($secondReference->dependency)->toBe($secondDependency);
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

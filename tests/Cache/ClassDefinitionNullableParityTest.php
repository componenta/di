<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Cache;

use Componenta\Config\Config;
use Componenta\DI\Cache\DiCacheGenerator;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Definition\ClassDefinition;

final class CompiledClassDefinitionNullableTarget
{
    public function __construct(public ?string $value) {}
}

it('keeps required nullable ClassDefinition constructor parameters equivalent in runtime and cache', function (): void {
    $definition = ClassDefinition::create(CompiledClassDefinitionNullableTarget::class);
    $dependencies = [
        ConfigKey::FACTORIES => [
            CompiledClassDefinitionNullableTarget::class => $definition,
        ],
    ];
    $runtime = ContainerBuilder::configureWithDependencies(
        new Config([]),
        $dependencies,
    )->build();
    $root = sys_get_temp_dir() . '/componenta-class-definition-nullable-' . bin2hex(random_bytes(5));
    $cacheFile = $root . '/container.php';

    try {
        (new DiCacheGenerator())->generate($dependencies, $cacheFile);
        $cached = ContainerBuilder::configureFromCache(
            new Config([]),
            require $cacheFile,
            $root,
        )->build();

        expect($runtime->make(CompiledClassDefinitionNullableTarget::class)->value)->toBeNull()
            ->and($cached->make(CompiledClassDefinitionNullableTarget::class)->value)->toBeNull();
    } finally {
        @unlink($cacheFile);
        @rmdir($root);
    }
});

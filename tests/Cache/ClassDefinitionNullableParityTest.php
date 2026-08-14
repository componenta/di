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

final class CompiledClassDefinitionUntypedTarget
{
    public mixed $value;

    public function __construct($value)
    {
        $this->value = $value;
    }
}

it('keeps required nullable and untyped ClassDefinition parameters equivalent in runtime and cache', function (): void {
    $dependencies = [
        ConfigKey::FACTORIES => [
            CompiledClassDefinitionNullableTarget::class => ClassDefinition::create(
                CompiledClassDefinitionNullableTarget::class,
            ),
            CompiledClassDefinitionUntypedTarget::class => ClassDefinition::create(
                CompiledClassDefinitionUntypedTarget::class,
            ),
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
            ->and($cached->make(CompiledClassDefinitionNullableTarget::class)->value)->toBeNull()
            ->and($runtime->make(CompiledClassDefinitionUntypedTarget::class)->value)->toBeNull()
            ->and($cached->make(CompiledClassDefinitionUntypedTarget::class)->value)->toBeNull();
    } finally {
        @unlink($cacheFile);
        @rmdir($root);
    }
});

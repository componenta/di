<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\Config\Config;
use Componenta\DI\Cache\DiCacheGenerator;
use Componenta\DI\Compile\Definition\DefinitionCodeGeneratorInterface;
use Componenta\DI\Compile\Definition\DefinitionCodeGeneratorRegistry;
use Componenta\DI\Compile\Definition\DefinitionCompiler;
use Componenta\DI\Compile\Definition\GeneratedDefinitionCode;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Definition\DefinitionInterface;
use LogicException;

final readonly class CacheCompiledDefinition implements DefinitionInterface
{
    public function __construct(public string $value) {}
}

final readonly class CacheCompiledDefinitionGenerator implements DefinitionCodeGeneratorInterface
{
    public function generate(string $id, DefinitionInterface $definition): GeneratedDefinitionCode
    {
        if (!$definition instanceof CacheCompiledDefinition) {
            throw new LogicException('Unexpected definition type.');
        }

        return new GeneratedDefinitionCode(sprintf(
            'static fn(): string => %s',
            var_export($definition->value, true),
        ));
    }
}

test('custom definition compiler survives persistent cache generation and bootstrap', function (): void {
    $path = sys_get_temp_dir()
        . '/componenta-di-custom-definition-'
        . bin2hex(random_bytes(5))
        . '.php';
    $registry = new DefinitionCodeGeneratorRegistry();
    $registry->register(
        CacheCompiledDefinition::class,
        new CacheCompiledDefinitionGenerator(),
    );
    $compiler = new DefinitionCompiler($registry);

    try {
        (new DiCacheGenerator($compiler))->generate([
            ConfigKey::FACTORIES => [
                'compiled.custom' => new CacheCompiledDefinition('compiled-value'),
            ],
        ], $path);

        $cache = require $path;
        expect($cache)->toBeArray();

        $container = ContainerBuilder::configureFromCache(
            new Config([]),
            $cache,
            dirname($path),
        )->build();

        expect($container->get('compiled.custom'))->toBe('compiled-value');
    } finally {
        if (is_file($path)) {
            @unlink($path);
        }
    }
});

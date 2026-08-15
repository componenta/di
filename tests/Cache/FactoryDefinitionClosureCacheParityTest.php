<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\DI\Cache\DiCacheGenerator;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Definition\Definition;

final readonly class FactoryDefinitionClosureCacheProduct
{
    public function __construct(public string $source) {}
}

it('keeps closure factory shorthand and FactoryDefinition equivalent in persistent cache', function (): void {
    $root = sys_get_temp_dir() . '/componenta-factory-definition-closure-cache-' . bin2hex(random_bytes(5));
    $rawCache = $root . '/raw.php';
    $definitionCache = $root . '/definition.php';

    $rawFactory = static fn($container, array $context = []) => new \FactoryDefinitionClosureCacheProduct(
        $context['source'] ?? 'raw',
    );
    $definitionFactory = static fn($container, array $context = []) => new \FactoryDefinitionClosureCacheProduct(
        $context['source'] ?? 'definition',
    );

    try {
        $generator = new DiCacheGenerator();
        $generator->generate([
            ConfigKey::FACTORIES => ['factory' => $rawFactory],
        ], $rawCache);
        $generator->generate([
            ConfigKey::FACTORIES => ['factory' => Definition::factory($definitionFactory)],
        ], $definitionCache);

        $raw = ContainerBuilder::configureFromCache(
            new Config([]),
            require $rawCache,
            $root,
        )->build();
        $definition = ContainerBuilder::configureFromCache(
            new Config([]),
            require $definitionCache,
            $root,
        )->build();

        expect($raw->make('factory', ['source' => 'cached'])->source)->toBe('cached')
            ->and($definition->make('factory', ['source' => 'cached'])->source)->toBe('cached');
    } finally {
        @unlink($rawCache);
        @unlink($definitionCache);
        @rmdir($root);
    }
});

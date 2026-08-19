<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\Caster\CasterProviderInterface;
use Componenta\Config\Config;
use Componenta\DI\Attribute\Cast;
use Componenta\DI\Cache\DiCacheGenerator;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Resolver\Attribute\AttributeProcessor;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
use Componenta\DI\Resolver\Parameter\AttributeParameterResolver;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Parameter\ParameterSourceAttributeInterface;
use Componenta\DI\Resolver\Parameter\RequestContextResolver;
use Componenta\DI\Tests\Support\TestCasterProvider;

final class AotValueDto
{
    public function __construct(#[Cast('int')] public int $value) {}
}

final class CachedDefinitionDto
{
    public function __construct(#[Cast('int')] public int $value) {}
}

function cleanupDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    foreach (glob($directory . '/*') ?: [] as $file) {
        is_dir($file) ? cleanupDirectory($file) : @unlink($file);
    }
    @rmdir($directory);
}

test('reflection and AOT execute the same parameter resolver semantics', function (): void {
    $directory = sys_get_temp_dir() . '/componenta-di-v5-aot-' . bin2hex(random_bytes(5));
    $builder = (new ContainerBuilder())
        ->addService(CasterProviderInterface::class, new TestCasterProvider());
    $development = $builder->build();

    try {
        $compiled = $builder->compileFactories([AotValueDto::class], $directory);
        $data = $builder->toArray();
        $dependencies = $data[ConfigKey::DEPENDENCIES];
        $dependencies[ConfigKey::FACTORIES] = array_replace(
            $dependencies[ConfigKey::FACTORIES] ?? [],
            $compiled,
        );
        $production = ContainerBuilder::configureFromCache(
            new Config([]),
            [
                'version' => ContainerBuilder::CACHE_VERSION,
                ConfigKey::DEPENDENCIES => $dependencies,
            ],
            $directory,
        )->build();

        $params = ['value' => '73'];
        expect($production->make(AotValueDto::class, $params)->value)
            ->toBe($development->make(AotValueDto::class, $params)->value)
            ->toBe(73);
    } finally {
        cleanupDirectory($directory);
    }
});

test('persistent ClassDefinition cache routes through the same parameter resolver path', function (): void {
    $directory = sys_get_temp_dir() . '/componenta-di-v5-cache-' . bin2hex(random_bytes(5));
    $file = $directory . '/container.php';

    try {
        (new DiCacheGenerator())->generate([
            ConfigKey::FACTORIES => [
                CachedDefinitionDto::class => ClassDefinition::create(CachedDefinitionDto::class)
                    ->constructor(['value' => '91']),
            ],
            ConfigKey::SERVICES => [
                CasterProviderInterface::class => new TestCasterProvider(),
            ],
        ], $file);
        $cache = require $file;
        $container = ContainerBuilder::configureFromCache(new Config([]), $cache, $directory)->build();

        expect($container->make(CachedDefinitionDto::class)->value)->toBe(91);
    } finally {
        cleanupDirectory($directory);
    }
});

test('cache envelopes are strictly versioned for the final v5 format', function (): void {
    expect(fn() => ContainerBuilder::configureFromCache(
        new Config([]),
        ['version' => 11, ConfigKey::DEPENDENCIES => []],
    ))->toThrow(InvalidConfigurationException::class);
});

test('final v5 exposes one bridge for parameter attributes plus convention resolvers', function (): void {
    foreach ([
        ParameterResolverInterface::class,
        ParameterSourceAttributeInterface::class,
        ParameterAttributeHandlerInterface::class,
        AttributeParameterResolver::class,
        RequestContextResolver::class,
        AttributeProcessor::class,
    ] as $class) {
        expect(class_exists($class) || interface_exists($class))->toBeTrue();
    }
});

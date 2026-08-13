<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\DI\Cache\DiCacheGenerator;
use Componenta\DI\Compile\Factory\CompiledFactoryDefinition;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\InvalidConfigurationException;

it('does not infer compiled factory path trust from an object restored at the cache boundary', function (): void {
    $root = sys_get_temp_dir() . '/componenta-compiled-object-trust-' . bin2hex(random_bytes(5));
    $base = $root . '/base';
    $outside = $root . '/outside.php';
    mkdir($base, 0777, true);
    file_put_contents($outside, '<?php throw new RuntimeException("must not execute");');

    try {
        $definition = new CompiledFactoryDefinition($outside, stdClass::class, 'create');
        $container = ContainerBuilder::configureFromCache(
            new Config([]),
            [
                'version' => ContainerBuilder::CACHE_VERSION,
                ConfigKey::DEPENDENCIES => [ConfigKey::FACTORIES => ['entry' => $definition]],
            ],
            $base,
        )->build();

        expect(fn() => $container->get('entry'))
            ->toThrow(InvalidConfigurationException::class, 'relative path');
    } finally {
        @unlink($outside);
        @rmdir($base);
        @rmdir($root);
    }
});

it('keeps compiled definitions path-confined after the real cache generator round trip', function (): void {
    $root = sys_get_temp_dir() . '/componenta-compiled-cache-roundtrip-' . bin2hex(random_bytes(5));
    $base = $root . '/base';
    $outside = $root . '/outside.php';
    $cacheFile = $root . '/container.php';
    mkdir($base, 0777, true);
    file_put_contents($outside, '<?php throw new RuntimeException("must not execute");');

    try {
        (new DiCacheGenerator())->generate(
            [
                ConfigKey::FACTORIES => [
                    'entry' => new CompiledFactoryDefinition($outside, stdClass::class, 'create'),
                ],
            ],
            $cacheFile,
        );

        $cache = require $cacheFile;
        expect($cache[ConfigKey::DEPENDENCIES][ConfigKey::FACTORIES]['entry'])
            ->toBeInstanceOf(CompiledFactoryDefinition::class);

        $container = ContainerBuilder::configureFromCache(new Config([]), $cache, $base)->build();

        expect(fn() => $container->get('entry'))
            ->toThrow(InvalidConfigurationException::class, 'relative path');
    } finally {
        @unlink($cacheFile);
        @unlink($outside);
        @rmdir($base);
        @rmdir($root);
    }
});

it('keeps direct programmatic compiled factory objects path-flexible while validating code', function (): void {
    $class = 'CompiledFactoryProgrammatic_' . bin2hex(random_bytes(6));
    $file = tempnam(sys_get_temp_dir(), 'componenta-di-programmatic-shard-');
    expect($file)->not->toBeFalse();
    /** @var non-empty-string $file */
    file_put_contents($file, sprintf(<<<'PHP'
<?php
final class %s
{
    public function __construct(array $parameterResolvers, array $attributeHandlers, \Componenta\DI\ProxyFactoryInterface $proxyFactory) {}
    public function create(array $parameters = []): string { return 'programmatic'; }
}
return %s::class;
PHP, $class, $class));

    try {
        $container = ContainerBuilder::configureWithDependencies(new Config([]), [
            ConfigKey::FACTORIES => ['entry' => new CompiledFactoryDefinition($file, $class, 'create')],
        ])->build();
        expect($container->get('entry'))->toBe('programmatic');
    } finally {
        @unlink($file);
    }
});

it('rejects a preloaded class from a different shard for a direct programmatic definition', function (): void {
    $root = sys_get_temp_dir() . '/componenta-programmatic-preloaded-' . bin2hex(random_bytes(5));
    $firstFile = $root . '/first.php';
    $secondFile = $root . '/second.php';
    $class = 'CompiledFactoryProgrammaticPreloaded_' . bin2hex(random_bytes(6));
    mkdir($root, 0777, true);

    $source = static function (string $value) use ($class): string {
        return sprintf(<<<'PHP'
<?php
final class %s
{
    public function __construct(array $parameterResolvers, array $attributeHandlers, \Componenta\DI\ProxyFactoryInterface $proxyFactory) {}
    public function create(array $parameters = []): string { return %s; }
}
return %s::class;
PHP, $class, var_export($value, true), $class);
    };
    file_put_contents($firstFile, $source('first'));
    file_put_contents($secondFile, $source('second'));

    try {
        expect(require $firstFile)->toBe($class);
        $container = ContainerBuilder::configureWithDependencies(new Config([]), [
            ConfigKey::FACTORIES => [
                'entry' => new CompiledFactoryDefinition($secondFile, $class, 'create'),
            ],
        ])->build();
        expect(fn() => $container->get('entry'))
            ->toThrow(InvalidConfigurationException::class, 'different shard');
    } finally {
        @unlink($firstFile);
        @unlink($secondFile);
        @rmdir($root);
    }
});

it('reuses an already loaded generated class from an identical shard in another cache root', function (): void {
    $root = sys_get_temp_dir() . '/componenta-identical-shard-roots-' . bin2hex(random_bytes(5));
    $firstBase = $root . '/first';
    $secondBase = $root . '/second';
    $file = 'shared.php';
    $class = 'CompiledFactorySharedRoot_' . bin2hex(random_bytes(6));
    mkdir($firstBase, 0777, true);
    mkdir($secondBase, 0777, true);

    $source = sprintf(<<<'PHP'
<?php
final class %s
{
    public function __construct(array $parameterResolvers, array $attributeHandlers, \Componenta\DI\ProxyFactoryInterface $proxyFactory) {}
    public function create(array $parameters = []): string { return 'shared-shard'; }
}
return %s::class;
PHP, $class, $class);
    file_put_contents($firstBase . '/' . $file, $source);
    file_put_contents($secondBase . '/' . $file, $source);
    $definition = (new CompiledFactoryDefinition($file, $class, 'create'))->encode();

    try {
        $cache = [
            'version' => ContainerBuilder::CACHE_VERSION,
            ConfigKey::DEPENDENCIES => [ConfigKey::FACTORIES => ['entry' => $definition]],
        ];
        $first = ContainerBuilder::configureFromCache(new Config([]), $cache, $firstBase)->build();
        $second = ContainerBuilder::configureFromCache(new Config([]), $cache, $secondBase)->build();
        expect($first->get('entry'))->toBe('shared-shard')
            ->and($second->get('entry'))->toBe('shared-shard');
    } finally {
        @unlink($firstBase . '/' . $file);
        @unlink($secondBase . '/' . $file);
        @rmdir($firstBase);
        @rmdir($secondBase);
        @rmdir($root);
    }
});

it('rejects an already loaded generated class when the second cache root contains different source', function (): void {
    $root = sys_get_temp_dir() . '/componenta-different-shard-roots-' . bin2hex(random_bytes(5));
    $firstBase = $root . '/first';
    $secondBase = $root . '/second';
    $file = 'shared.php';
    $class = 'CompiledFactoryDifferentRoot_' . bin2hex(random_bytes(6));
    mkdir($firstBase, 0777, true);
    mkdir($secondBase, 0777, true);

    $source = static function (string $value) use ($class): string {
        return sprintf(<<<'PHP'
<?php
final class %s
{
    public function __construct(array $parameterResolvers, array $attributeHandlers, \Componenta\DI\ProxyFactoryInterface $proxyFactory) {}
    public function create(array $parameters = []): string { return %s; }
}
return %s::class;
PHP, $class, var_export($value, true), $class);
    };
    file_put_contents($firstBase . '/' . $file, $source('first'));
    file_put_contents($secondBase . '/' . $file, $source('second'));
    $definition = (new CompiledFactoryDefinition($file, $class, 'create'))->encode();

    try {
        $cache = [
            'version' => ContainerBuilder::CACHE_VERSION,
            ConfigKey::DEPENDENCIES => [ConfigKey::FACTORIES => ['entry' => $definition]],
        ];
        $first = ContainerBuilder::configureFromCache(new Config([]), $cache, $firstBase)->build();
        $second = ContainerBuilder::configureFromCache(new Config([]), $cache, $secondBase)->build();
        expect($first->get('entry'))->toBe('first')
            ->and(fn() => $second->get('entry'))
            ->toThrow(InvalidConfigurationException::class, 'different shard');
    } finally {
        @unlink($firstBase . '/' . $file);
        @unlink($secondBase . '/' . $file);
        @rmdir($firstBase);
        @rmdir($secondBase);
        @rmdir($root);
    }
});

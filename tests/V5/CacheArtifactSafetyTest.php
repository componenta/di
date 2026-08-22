<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\Config\Config;
use Componenta\DI\Cache\DiCacheGenerator;
use Componenta\DI\Compile\Definition\DefinitionCompilerInterface;
use Componenta\DI\Compile\Definition\GeneratedDefinitionCode;
use Componenta\DI\Compile\Factory\CompiledFactoryDefinition;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\CompilationException;
use Componenta\DI\Exception\InvalidConfigurationException;

final readonly class InvalidPhpDefinitionCompiler implements DefinitionCompilerInterface
{
    public function compile(array $dependencies): array
    {
        $dependencies[ConfigKey::FACTORIES] = [
            'broken' => new GeneratedDefinitionCode('fn( =>'),
        ];

        return $dependencies;
    }
}

final readonly class InvalidDependencyDefinitionCompiler implements DefinitionCompilerInterface
{
    public function compile(array $dependencies): array
    {
        $dependencies[ConfigKey::ALIASES] = ['broken.alias' => ''];

        return $dependencies;
    }
}

final readonly class InvalidGeneratedBindingCompiler implements DefinitionCompilerInterface
{
    public function compile(array $dependencies): array
    {
        $dependencies[ConfigKey::FACTORIES] = [
            Config::class => new GeneratedDefinitionCode('static fn(): null => null'),
        ];

        return $dependencies;
    }
}

test('invalid generated PHP never replaces an existing persistent cache artifact', function (): void {
    $path = sys_get_temp_dir() . '/componenta-di-invalid-cache-' . bin2hex(random_bytes(5)) . '.php';
    $sentinel = "<?php\nreturn ['sentinel' => true];\n";
    file_put_contents($path, $sentinel);

    try {
        expect(fn() => (new DiCacheGenerator(new InvalidPhpDefinitionCompiler()))->generate([], $path))
            ->toThrow(CompilationException::class, 'PHP compile validation');

        expect(file_get_contents($path))->toBe($sentinel)
            ->and(glob($path . '.tmp.*') ?: [])->toBe([]);
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
        foreach (glob($path . '.tmp.*') ?: [] as $temporary) {
            unlink($temporary);
        }
    }
});

test('invalid non-factory compiler output is rejected before cache replacement', function (): void {
    $path = sys_get_temp_dir() . '/componenta-di-invalid-compiler-output-' . bin2hex(random_bytes(5)) . '.php';
    $sentinel = "<?php\nreturn ['sentinel' => true];\n";
    file_put_contents($path, $sentinel);

    try {
        expect(fn() => (new DiCacheGenerator(new InvalidDependencyDefinitionCompiler()))->generate([], $path))
            ->toThrow(InvalidConfigurationException::class, 'Aliases must map non-empty string ids');

        expect(file_get_contents($path))->toBe($sentinel)
            ->and(glob($path . '.tmp.*') ?: [])->toBe([]);
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
        foreach (glob($path . '.tmp.*') ?: [] as $temporary) {
            unlink($temporary);
        }
    }
});

test('generated factory ids still obey canonical protected binding rules', function (): void {
    $path = sys_get_temp_dir() . '/componenta-di-invalid-generated-binding-' . bin2hex(random_bytes(5)) . '.php';
    $sentinel = "<?php\nreturn ['sentinel' => true];\n";
    file_put_contents($path, $sentinel);

    try {
        expect(fn() => (new DiCacheGenerator(new InvalidGeneratedBindingCompiler()))->generate([], $path))
            ->toThrow(InvalidConfigurationException::class, 'protected DI id');

        expect(file_get_contents($path))->toBe($sentinel)
            ->and(glob($path . '.tmp.*') ?: [])->toBe([]);
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
        foreach (glob($path . '.tmp.*') ?: [] as $temporary) {
            unlink($temporary);
        }
    }
});

test('compiled factory objects restored at the cache boundary remain path confined', function (): void {
    $root = sys_get_temp_dir()
        . '/componenta-di-cache-object-trust-'
        . bin2hex(random_bytes(5));
    $base = $root . '/base';
    $outside = $root . '/outside.php';
    mkdir($base, 0o775, true);
    file_put_contents($outside, '<?php throw new \\RuntimeException("must not execute");');

    try {
        $container = ContainerBuilder::configureFromCache(
            new Config([]),
            [
                'version' => ContainerBuilder::CACHE_VERSION,
                ConfigKey::DEPENDENCIES => [
                    ConfigKey::FACTORIES => [
                        'entry' => new CompiledFactoryDefinition(
                            $outside,
                            \stdClass::class,
                            'create',
                        ),
                    ],
                ],
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

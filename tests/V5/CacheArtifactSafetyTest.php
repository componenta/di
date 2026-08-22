<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\DI\Cache\DiCacheGenerator;
use Componenta\DI\Compile\Definition\DefinitionCompilerInterface;
use Componenta\DI\Compile\Definition\GeneratedDefinitionCode;
use Componenta\DI\ConfigKey;
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
            ->toThrow(InvalidConfigurationException::class, 'Alias target must be a non-empty');

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

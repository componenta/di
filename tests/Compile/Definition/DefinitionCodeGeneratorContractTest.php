<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Compile\Definition;

use Closure;
use Componenta\Config\Config;
use Componenta\DI\Cache\DiCacheGenerator;
use Componenta\DI\Compile\Definition\DefinitionCodeGeneratorInterface;
use Componenta\DI\Compile\Definition\DefinitionCodeGeneratorRegistry;
use Componenta\DI\Compile\Definition\DefinitionCompiler;
use Componenta\DI\Compile\Definition\GeneratedDefinitionCode;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Definition\DefinitionInterface;
use Componenta\DI\Exception\InvalidConfigurationException;
use InvalidArgumentException;

interface CustomCompiledDefinitionInterface extends DefinitionInterface {}

final readonly class CustomCompiledDefinition implements CustomCompiledDefinitionInterface
{
    public function __construct(public mixed $value) {}
}

final readonly class CustomCompiledProduct
{
    public function __construct(public string $id) {}
}

final class CustomDefinitionCodeGenerator implements DefinitionCodeGeneratorInterface
{
    public ?DefinitionInterface $received = null;

    public function generate(
        string $id,
        DefinitionInterface $definition,
    ): GeneratedDefinitionCode {
        $this->received = $definition;

        return new GeneratedDefinitionCode('static fn() => ' . var_export($id, true));
    }
}

final class CustomProductDefinitionCodeGenerator implements DefinitionCodeGeneratorInterface
{
    public function generate(
        string $id,
        DefinitionInterface $definition,
    ): GeneratedDefinitionCode {
        return new GeneratedDefinitionCode(sprintf(
            'static fn() => new \\%s(%s)',
            CustomCompiledProduct::class,
            var_export($id, true),
        ));
    }
}

it('selects a generator by definition interface while the generator contract accepts DefinitionInterface', function (): void {
    $definition = new CustomCompiledDefinition('value');
    $generator = new CustomDefinitionCodeGenerator();
    $registry = new DefinitionCodeGeneratorRegistry();
    $registry->register(CustomCompiledDefinitionInterface::class, $generator);

    $compiled = (new DefinitionCompiler($registry))->compile([
        ConfigKey::FACTORIES => ['custom' => $definition],
    ]);

    expect($generator->received)->toBe($definition)
        ->and($compiled[ConfigKey::FACTORIES]['custom'])
        ->toBeInstanceOf(GeneratedDefinitionCode::class)
        ->and($compiled[ConfigKey::FACTORIES]['custom']->code)
        ->toBe("static fn() => 'custom'");
});

it('compiles a custom declarative definition through the real persistent cache writer', function (): void {
    $root = sys_get_temp_dir() . '/componenta-custom-definition-' . bin2hex(random_bytes(5));
    $cacheFile = $root . '/container.php';
    $registry = new DefinitionCodeGeneratorRegistry();
    $registry->register(
        CustomCompiledDefinitionInterface::class,
        new CustomProductDefinitionCodeGenerator(),
    );

    try {
        (new DiCacheGenerator(new DefinitionCompiler($registry)))->generate([
            ConfigKey::FACTORIES => [
                'custom.definition' => new CustomCompiledDefinition('value'),
            ],
        ], $cacheFile);

        $cache = require $cacheFile;
        expect($cache[ConfigKey::DEPENDENCIES][ConfigKey::FACTORIES]['custom.definition'])
            ->toBeInstanceOf(Closure::class);

        $container = ContainerBuilder::configureFromCache(
            new Config([]),
            $cache,
            $root,
        )->build();
        $entry = $container->make('custom.definition');

        expect($entry)->toBeInstanceOf(CustomCompiledProduct::class)
            ->and($entry->id)->toBe('custom.definition');
    } finally {
        @unlink($cacheFile);
        @rmdir($root);
    }
});

it('fails cache generation when a custom declarative definition has no code generator', function (): void {
    $path = sys_get_temp_dir() . '/componenta-custom-definition-missing-' . bin2hex(random_bytes(5)) . '.php';

    try {
        expect(fn() => (new DiCacheGenerator())->generate([
            ConfigKey::FACTORIES => [
                'custom.definition' => new CustomCompiledDefinition('value'),
            ],
        ], $path))->toThrow(InvalidConfigurationException::class);
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

it('rejects an uncompiled custom definition when building the runtime resolver', function (): void {
    $builder = ContainerBuilder::configureWithDependencies(
        new Config([]),
        [
            ConfigKey::FACTORIES => [
                'custom.definition' => new CustomCompiledDefinition('value'),
            ],
        ],
    );

    expect(fn() => $builder->build())
        ->toThrow(InvalidConfigurationException::class);
});

it('prefers an exact definition generator over a broader interface generator', function (): void {
    $definition = new CustomCompiledDefinition('value');
    $interfaceGenerator = new CustomDefinitionCodeGenerator();
    $exactGenerator = new class () implements DefinitionCodeGeneratorInterface {
        public function generate(string $id, DefinitionInterface $definition): GeneratedDefinitionCode
        {
            return new GeneratedDefinitionCode("static fn() => 'exact'");
        }
    };
    $registry = new DefinitionCodeGeneratorRegistry();
    $registry->register(CustomCompiledDefinitionInterface::class, $interfaceGenerator);
    $registry->register(CustomCompiledDefinition::class, $exactGenerator);

    expect($registry->find($definition))->toBe($exactGenerator);
});

it('rejects registry keys that are not definition classes or interfaces', function (): void {
    $registry = new DefinitionCodeGeneratorRegistry();

    expect(fn() => $registry->register(\stdClass::class, new CustomDefinitionCodeGenerator()))
        ->toThrow(InvalidArgumentException::class);
});

it('leaves definitions without a registered code generator untouched', function (): void {
    $definition = new CustomCompiledDefinition('value');
    $compiled = (new DefinitionCompiler(new DefinitionCodeGeneratorRegistry()))->compile([
        ConfigKey::FACTORIES => ['custom' => $definition],
    ]);

    expect($compiled[ConfigKey::FACTORIES]['custom'])->toBe($definition);
});

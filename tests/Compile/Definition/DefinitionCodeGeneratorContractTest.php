<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Compile\Definition;

use Componenta\DI\Compile\Definition\DefinitionCodeGeneratorInterface;
use Componenta\DI\Compile\Definition\DefinitionCodeGeneratorRegistry;
use Componenta\DI\Compile\Definition\DefinitionCompiler;
use Componenta\DI\Compile\Definition\GeneratedDefinitionCode;
use Componenta\DI\ConfigKey;
use Componenta\DI\Definition\DefinitionInterface;

final readonly class CustomCompiledDefinition implements DefinitionInterface
{
    public function __construct(public mixed $value) {}
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

it('selects a generator by definition type while the generator contract accepts DefinitionInterface', function (): void {
    $definition = new CustomCompiledDefinition('value');
    $generator = new CustomDefinitionCodeGenerator();
    $registry = new DefinitionCodeGeneratorRegistry();
    $registry->register(CustomCompiledDefinition::class, $generator);

    $compiled = (new DefinitionCompiler($registry))->compile([
        ConfigKey::FACTORIES => ['custom' => $definition],
    ]);

    expect($generator->received)->toBe($definition)
        ->and($compiled[ConfigKey::FACTORIES]['custom'])
        ->toBeInstanceOf(GeneratedDefinitionCode::class)
        ->and($compiled[ConfigKey::FACTORIES]['custom']->code)
        ->toBe("static fn() => 'custom'");
});

it('leaves definitions without a registered code generator untouched', function (): void {
    $definition = new CustomCompiledDefinition('value');
    $compiled = (new DefinitionCompiler(new DefinitionCodeGeneratorRegistry()))->compile([
        ConfigKey::FACTORIES => ['custom' => $definition],
    ]);

    expect($compiled[ConfigKey::FACTORIES]['custom'])->toBe($definition);
});

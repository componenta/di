<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Definition;

use Componenta\DI\ConfigKey;
use Componenta\DI\Definition\DefinitionInterface;

/** Compiles only declarative builder/config definitions; runtime set() state is not visible here. */
final readonly class DefinitionCompiler implements DefinitionCompilerInterface
{
    public function __construct(
        private DefinitionCodeGeneratorRegistry $generators,
    ) {}

    public static function createDefault(): self
    {
        return new self(DefaultDefinitionCodeGenerators::create());
    }

    public function compile(array $dependencies): array
    {
        $factories = $dependencies[ConfigKey::FACTORIES] ?? null;

        if (!is_array($factories)) {
            return $dependencies;
        }

        foreach ($factories as $id => $definition) {
            if (!$definition instanceof DefinitionInterface) {
                continue;
            }

            $generator = $this->generators->find($definition);
            if ($generator === null) {
                continue;
            }

            $factories[$id] = $generator->generate($id, $definition);
        }

        $dependencies[ConfigKey::FACTORIES] = $factories;

        return $dependencies;
    }
}

<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Definition;

use Componenta\DI\Definition\ClassDefinition;

/** Creates the built-in definition code-generator registry. */
final class DefaultDefinitionCodeGenerators
{
    public static function create(): DefinitionCodeGeneratorRegistry
    {
        $registry = new DefinitionCodeGeneratorRegistry();
        $registry->register(
            ClassDefinition::class,
            new ClassDefinitionCodeGenerator(),
        );

        return $registry;
    }

    private function __construct() {}
}

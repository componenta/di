<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Definition;

/** Built-in v5 definitions are exported as immutable data and resolved by runtime pipelines. */
final class DefaultDefinitionCodeGenerators
{
    public static function create(): DefinitionCodeGeneratorRegistry
    {
        return new DefinitionCodeGeneratorRegistry();
    }

    private function __construct() {}
}

<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Definition;

use Componenta\DI\Definition\DefinitionInterface;

/** Generates a PHP expression for one declarative DI definition. */
interface DefinitionCodeGeneratorInterface
{
    public function generate(
        string $id,
        DefinitionInterface $definition,
    ): GeneratedDefinitionCode;
}

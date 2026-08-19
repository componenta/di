<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Definition;

use Componenta\DI\Exception\CompilationException;
use Componenta\DI\Exception\InvalidConfigurationException;

/** Compiles declarative definitions contained in normalized DI dependencies. */
interface DefinitionCompilerInterface
{
    /**
     * @param array<string, mixed> $dependencies
     * @return array<string, mixed>
     * @throws InvalidConfigurationException Invalid compile configuration.
     * @throws CompilationException Foreign code-generator failure.
     */
    public function compile(array $dependencies): array;
}

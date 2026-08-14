<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Definition;

/** Compiles declarative definitions contained in normalized DI dependencies. */
interface DefinitionCompilerInterface
{
    /**
     * @param array<string, mixed> $dependencies
     * @return array<string, mixed>
     */
    public function compile(array $dependencies): array;
}

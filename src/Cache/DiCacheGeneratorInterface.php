<?php

declare(strict_types=1);

namespace Componenta\DI\Cache;

use Componenta\DI\Exception\CompilationException;
use Componenta\DI\Exception\InvalidConfigurationException;

interface DiCacheGeneratorInterface
{
    /**
     * @param array<string, mixed> $dependencies
     * @throws InvalidConfigurationException Invalid dependency configuration.
     * @throws CompilationException Cache serialization, filesystem or activation failure.
     */
    public function generate(array $dependencies, string $path): void;
}

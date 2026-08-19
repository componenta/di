<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Definition;

use Componenta\DI\Exception\InvalidConfigurationException;

/**
 * Immutable PHP expression returned by definition code generators.
 *
 * The persistent-cache writer treats an instance as executable generated code
 * only when that exact instance came from the configured definition compiler.
 */
final readonly class GeneratedDefinitionCode
{
    public function __construct(public string $code)
    {
        if ($code === '') {
            throw new InvalidConfigurationException('Generated definition code must not be empty.');
        }
    }
}

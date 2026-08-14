<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Definition;

/** Compiler-only PHP expression inserted verbatim into the persistent cache. */
final readonly class GeneratedDefinitionCode
{
    public function __construct(public string $code)
    {
        if ($code === '') {
            throw new \InvalidArgumentException('Generated definition code must not be empty.');
        }
    }
}

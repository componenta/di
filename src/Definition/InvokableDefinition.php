<?php

declare(strict_types=1);

namespace Componenta\DI\Definition;

/**
 * Class that can be instantiated without constructor arguments.
 *
 * @example
 * ```php
 * new InvokableDefinition(SimpleService::class)
 * ```
 */
final readonly class InvokableDefinition implements DefinitionInterface
{
    /**
     * @param class-string $value Class name to instantiate.
     */
    public function __construct(
        public string $value,
    ) {}
}

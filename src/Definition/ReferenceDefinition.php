<?php

declare(strict_types=1);

namespace Componenta\DI\Definition;

/**
 * Reference to another container entry.
 *
 * @example
 * ```php
 * ClassDefinition::create(Service::class)
 *     ->constructor(['logger' => new ReferenceDefinition(LoggerInterface::class)])
 * ```
 */
final readonly class ReferenceDefinition implements DefinitionInterface
{
    /**
     * @param string $value Container entry id to look up.
     */
    public function __construct(
        public string $value,
    ) {}
}

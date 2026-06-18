<?php

declare(strict_types=1);

namespace Componenta\DI\Definition;

/**
 * Describes how a container entry should be created.
 */
interface DefinitionInterface
{
    /**
     * The definition value (class name, factory, reference, etc.).
     */
    public mixed $value { get; }
}

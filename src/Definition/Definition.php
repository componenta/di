<?php

declare(strict_types=1);

namespace Componenta\DI\Definition;

/** Static factory for immutable dependency definitions. */
final class Definition
{
    /**
     * @param callable(\Componenta\Config\ContainerValue, array<string|int, mixed>):mixed $factory
     */
    public static function factory(callable $factory): FactoryDefinition
    {
        return new FactoryDefinition($factory);
    }

    public static function reference(string $entryId): ReferenceDefinition
    {
        return new ReferenceDefinition($entryId);
    }

    /** @param class-string $className */
    public static function invokable(string $className): InvokableDefinition
    {
        return new InvokableDefinition($className);
    }
}

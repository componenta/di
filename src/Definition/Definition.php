<?php

declare(strict_types=1);

namespace Componenta\DI\Definition;

/** Static factory for immutable dependency definitions. */
final class Definition
{
    /**
     * Factory callables may declare any 0/1/2-argument prefix compatible with
     * the runtime `(ContainerValue, array)` invocation ABI.
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

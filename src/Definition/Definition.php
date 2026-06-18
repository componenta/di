<?php

declare(strict_types=1);

namespace Componenta\DI\Definition;

/**
 * Static factory for creating definitions.
 *
 * @example
 * ```php
 * return [
 *     LoggerInterface::class => Definition::factory(fn($c) => new Logger()),
 *     UserService::class => Definition::autowire(UserService::class),
 *     'db' => Definition::reference(Connection::class),
 * ];
 * ```
 */
final class Definition
{
    public static function factory(callable $factory): FactoryDefinition
    {
        return new FactoryDefinition($factory);
    }

    public static function autowire(string $className): ClassDefinition
    {
        return new ClassDefinition($className);
    }

    public static function reference(string $entryId): ReferenceDefinition
    {
        return new ReferenceDefinition($entryId);
    }

    public static function invokable(string $className): InvokableDefinition
    {
        return new InvokableDefinition($className);
    }
}

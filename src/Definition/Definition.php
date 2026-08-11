<?php

declare(strict_types=1);

namespace Componenta\DI\Definition;

/**
 * Static factory for creating definitions.
 *
 * `ReferenceDefinition` is an argument value for a `ClassDefinition`; it is
 * not a standalone container entry definition.
 *
 * @example
 * ```php
 * return [
 *     LoggerInterface::class => Definition::factory(
 *         fn($c, array $context) => new Logger(),
 *     ),
 *     UserService::class => Definition::autowire(UserService::class)
 *         ->constructor([
 *             'connection' => Definition::reference(Connection::class),
 *         ]),
 * ];
 * ```
 */
final class Definition
{
    /**
     * @param callable(\Componenta\Config\ContainerValue, array<string|int, mixed>):mixed $factory
     */
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

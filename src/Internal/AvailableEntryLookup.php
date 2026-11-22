<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Componenta\DI\Container;
use Componenta\DI\Resolver\Entry\EntryParameters;
use Psr\Container\ContainerInterface;

/** Entry selection for autowiring and callable routing. @internal */
abstract class AvailableEntryLookup extends ContainerEntryOperations
{
    public static function has(ContainerInterface $container, string $id): bool
    {
        return $container instanceof Container
            ? $container->hasEntry($id, requireResolvable: true)
            : $container->has($id);
    }

    public static function get(ContainerInterface $container, string $id): mixed
    {
        return $container instanceof Container
            ? $container->getEntry($id, requireResolvable: true)
            : $container->get($id);
    }

    public static function make(Container $container, string $id, EntryParameters $parameters): object
    {
        return $container->makeEntry($id, $parameters);
    }
}

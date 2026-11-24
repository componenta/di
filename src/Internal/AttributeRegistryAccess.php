<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;

/** Builds a planner on the registry and observes declaration discovery. @internal */
abstract class AttributeRegistryAccess extends AttributeRegistryScope
{
    public static function plans(AttributeDefinitionRegistry $registry): AttributePlans
    {
        return new AttributePlans($registry->definitions);
    }

    public static function refreshAvailability(AttributeDefinitionRegistry $registry): int
    {
        return $registry->definitions->refreshAvailability();
    }

    public static function refreshLoaded(AttributeDefinitionRegistry $registry): int
    {
        return $registry->definitions->refreshLoaded();
    }
}

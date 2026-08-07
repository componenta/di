<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use ReflectionClass;

/**
 * Single structural eligibility rule for reflection and generated entries.
 *
 * Publicly instantiable internal classes remain supported. A user-defined
 * concrete class with an inaccessible constructor is also eligible because a
 * before-instantiation handler may deliberately allocate it without invoking
 * that constructor. Anonymous and non-instantiable internal classes are never
 * stable container entry ids.
 */
final class EntryClassEligibility
{
    public static function allows(ReflectionClass $class): bool
    {
        if ($class->isAnonymous()
            || $class->isInterface()
            || $class->isTrait()
            || $class->isAbstract()
            || $class->isEnum()
        ) {
            return false;
        }

        return $class->isInstantiable() || $class->isUserDefined();
    }

    private function __construct() {}
}

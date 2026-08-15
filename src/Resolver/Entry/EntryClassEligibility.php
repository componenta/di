<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use ReflectionClass;

/** Single structural eligibility rule for reflection and generated entries. */
final class EntryClassEligibility
{
    /** @param ReflectionClass<object> $class */
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

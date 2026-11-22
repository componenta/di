<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Composition;

use Componenta\DI\Internal\AttributePlanScope;
use Componenta\DI\Internal\AttributeRegistryAccess;
use Componenta\DI\Internal\ReflectionSnapshot;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;

/** Builds a validated plan for the caller's declaration. */
final class AttributePlanBuilder extends AttributePlanScope
{
    public function __construct(AttributeDefinitionRegistry $registry)
    {
        parent::__construct(AttributeRegistryAccess::plans($registry));
    }

    /** @param ReflectionClass<covariant object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $target */
    public function build(ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target): AttributePlan
    {
        return $this->planning->build(ReflectionSnapshot::target($target));
    }
}

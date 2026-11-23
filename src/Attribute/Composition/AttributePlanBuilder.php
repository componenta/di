<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Composition;

use Closure;
use Componenta\DI\Exception\AttributeCompositionException;
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

    /**
     * Pass the closure itself for its instance-specific invocation method.
     * Derived reflectors supply native ReflectionAttribute declarations.
     *
     * @param Closure|ReflectionClass<covariant object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $target
     * @throws AttributeCompositionException If a target has no declaration the builder can own or its attributes are invalid.
     */
    public function build(
        Closure|ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target,
    ): AttributePlan {
        ReflectionSnapshot::assertPlanTarget($target);
        return $this->planning->build($target instanceof Closure ? $target : ReflectionSnapshot::target($target));
    }
}

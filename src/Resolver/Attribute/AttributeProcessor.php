<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Attribute;

use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Internal\AttributeExecution;
use Componenta\DI\Internal\AttributePlanAccess;
use Componenta\DI\Internal\ObjectAttributeScope;
use Componenta\DI\Internal\ReflectionSnapshot;
use ReflectionClass;

/** Prepares and inspects the handlers used by an object creation pipeline. */
final class AttributeProcessor extends ObjectAttributeScope
{
    public function __construct(AttributePlanBuilder $plans)
    {
        parent::__construct(new AttributeExecution(AttributePlanAccess::plans($plans), AttributePlanAccess::bootstrap($plans)));
    }

    /** @param ReflectionClass<object> $class */
    public function prepare(ReflectionClass $class): void
    {
        $this->execution->prepare(ReflectionSnapshot::class($class));
    }

    /** @param ReflectionClass<object> $class */
    public function hasHandlers(ReflectionClass $class): bool
    {
        return $this->execution->hasHandlers(ReflectionSnapshot::class($class));
    }
}

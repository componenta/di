<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Handler;

use Componenta\DI\ResolutionContext;
use ReflectionClass;

interface ConstructorPolicyHandlerInterface extends AttributeHandlerInterface
{
    /** @param ReflectionClass<object> $class */
    public function useConstructor(
        object $attribute,
        ReflectionClass $class,
        ResolutionContext $context,
    ): bool;
}

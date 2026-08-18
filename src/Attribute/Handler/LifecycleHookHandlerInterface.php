<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Handler;

use Componenta\DI\ResolutionContext;
use ReflectionClass;

interface LifecycleHookHandlerInterface extends AttributeHandlerInterface
{
    /** @param ReflectionClass<object> $class */
    public function run(
        object $attribute,
        object $entry,
        ReflectionClass $class,
        ResolutionContext $context,
    ): void;
}

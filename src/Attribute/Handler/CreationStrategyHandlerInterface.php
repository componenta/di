<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Handler;

use Componenta\DI\Object\CreationStrategy;
use Componenta\DI\ResolutionContext;
use ReflectionClass;

interface CreationStrategyHandlerInterface extends AttributeHandlerInterface
{
    /** @param ReflectionClass<object> $class */
    public function strategy(
        object $attribute,
        ReflectionClass $class,
        ResolutionContext $context,
    ): CreationStrategy;
}

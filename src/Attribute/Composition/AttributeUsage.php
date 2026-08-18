<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Composition;

use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;

/** One instantiated DI attribute together with its semantic definition. */
final readonly class AttributeUsage
{
    /** @param ReflectionClass<object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $target */
    public function __construct(
        public object $attribute,
        public AttributeDefinition $definition,
        public ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target,
        public int $declarationOrder,
    ) {}
}

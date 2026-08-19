<?php

declare(strict_types=1);

namespace Componenta\DI\Object;

use Componenta\DI\Attribute\Composition\AttributePlan;
use ReflectionClass;

/** Fully discovered immutable runtime metadata for one object class. */
final readonly class ObjectMetadata
{
    /** @param ReflectionClass<object> $class */
    public function __construct(
        public ReflectionClass $class,
        public AttributePlan $classPlan,
    ) {}
}

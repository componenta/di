<?php

declare(strict_types=1);

namespace Componenta\DI\Object;

use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Resolver\Target\ParameterTarget;
use ReflectionClass;
use ReflectionMethod;

/** Fully discovered immutable runtime metadata for one object class. */
final readonly class ObjectMetadata
{
    /**
     * @param ReflectionClass<object> $class
     * @param list<ParameterTarget> $constructorTargets
     */
    public function __construct(
        public ReflectionClass $class,
        public AttributePlan $classPlan,
        public ?ReflectionMethod $constructor,
        public array $constructorTargets,
        public bool $hasAttributeHandlers,
    ) {}
}

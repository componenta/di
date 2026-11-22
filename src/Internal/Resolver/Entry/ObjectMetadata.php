<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Entry;

use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Resolver\Target\ParameterTarget;
use ReflectionClass;
use ReflectionMethod;
use ReflectionObject;

/**
 * Fully discovered immutable runtime metadata for one object class.
 *
 * @internal
 */
final class ObjectMetadata
{
    /** @var ReflectionClass<object> */
    public ReflectionClass $attributeTarget {
        get => $this->instanceReflection?->getName() === $this->class->getName()
            ? $this->instanceReflection
            : $this->class;
    }

    /**
     * @param ReflectionClass<object> $class
     * @param list<ParameterTarget> $constructorTargets
     * @param ReflectionObject<object>|null $instanceReflection
     */
    public function __construct(
        public readonly ReflectionClass $class,
        public readonly AttributePlan $classPlan,
        public readonly ?ReflectionMethod $constructor,
        public readonly array $constructorTargets,
        public readonly bool $hasAttributeHandlers,
        private readonly ?ReflectionObject $instanceReflection,
    ) {}
}

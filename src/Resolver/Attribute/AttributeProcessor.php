<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Attribute;

use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;
use LogicException;

/**
 * Scans and binds class/property/method attributes once, then executes only
 * the preselected handlers for the requested phase.
 *
 * Parameter attributes are deliberately excluded: their first-match order is
 * owned by ParametersResolver and must not be bypassed by a class scan.
 */
final class AttributeProcessor
{
    /** @var array<class-string, array{version: int, plan: AttributeExecutionPlan}> */
    private array $cache = [];

    public function __construct(
        public readonly AttributeHandlerRegistry $registry,
    ) {}

    public function process(
        ReflectionClass $class,
        AttributePhase $phase,
        ObjectCreationContext $context,
    ): void {
        $this->processPlan($this->plan($class), $phase, $context);
    }

    public function processPlan(
        AttributeExecutionPlan $plan,
        AttributePhase $phase,
        ObjectCreationContext $context,
    ): void {
        foreach ($plan->forPhase($phase) as $invocation) {
            $invocation->handler->handle(
                $invocation->newAttribute(),
                $invocation->target,
                $context,
            );
        }
    }

    /**
     * Exposes the immutable, ordered phase map to the later code-generation
     * stage without rescanning native reflection.
     *
     * @return array{
     *     before: list<AttributeInvocation>,
     *     after: list<AttributeInvocation>
     * }
     */
    public function invocations(ReflectionClass $class): array
    {
        $plan = $this->plan($class);

        return [
            'before' => $plan->before,
            'after' => $plan->after,
        ];
    }

    public function plan(ReflectionClass $class): AttributeExecutionPlan
    {
        $className = $class->getName();
        $version = $this->registry->version;
        $cached = $this->cache[$className] ?? null;

        if ($cached !== null && $cached['version'] === $version) {
            return $cached['plan'];
        }

        $before = [];
        $after = [];
        $declarationOrder = 0;
        $registrations = $this->registry->registrations();

        $this->collect(
            $class,
            $registrations,
            $before,
            $after,
            $declarationOrder,
        );

        foreach (self::properties($class) as $property) {
            $this->collect(
                $property,
                $registrations,
                $before,
                $after,
                $declarationOrder,
            );
        }

        foreach (self::methods($class) as $method) {
            $this->collect(
                $method,
                $registrations,
                $before,
                $after,
                $declarationOrder,
            );
        }

        self::sort($before);
        self::sort($after);

        if ($version !== $this->registry->version) {
            throw new LogicException(
                'Attribute handler supportsAttribute() must not mutate the handler registry.',
            );
        }

        $plan = new AttributeExecutionPlan($before, $after);
        $this->cache[$className] = [
            'version' => $version,
            'plan' => $plan,
        ];

        return $plan;
    }

    /**
     * Returns every attribute class name present in the entry metadata, not
     * only attributes currently claimed by a registered handler.
     *
     * An unsupported attribute can become supported after its own class or
     * inheritance hierarchy changes while the entry source and handler chain
     * stay unchanged. Fingerprinting only bound invocations would then accept
     * a stale generated factory that silently skips the newly applicable
     * handler. Missing attribute classes are retained as names as well, so a
     * class that becomes autoloadable later also invalidates the artifact.
     *
     * @return list<class-string>
     */
    public function sourceAttributeClasses(ReflectionClass $class): array
    {
        $classes = [];

        self::collectTargetAttributeClasses($class, $classes);

        foreach (self::properties($class) as $property) {
            self::collectTargetAttributeClasses($property, $classes);
        }

        $constructor = $class->getConstructor();
        if ($constructor !== null) {
            self::collectParameterAttributeClasses($constructor->getParameters(), $classes);
        }

        // A class-level compilable handler may refer to a method indirectly
        // (for example SetUp). Include both method attributes and parameter
        // attributes while reusing the processor's canonical hierarchy walk.
        foreach (self::methods($class) as $method) {
            self::collectTargetAttributeClasses($method, $classes);
            self::collectParameterAttributeClasses($method->getParameters(), $classes);
        }

        return array_keys($classes);
    }

    /**
     * @param ReflectionClass|ReflectionProperty|ReflectionMethod $target
     * @param array<class-string, true> $classes
     */
    private static function collectTargetAttributeClasses(
        ReflectionClass|ReflectionProperty|ReflectionMethod $target,
        array &$classes,
    ): void {
        /** @var ReflectionAttribute<object> $attribute */
        foreach ($target->getAttributes() as $attribute) {
            /** @var class-string $attributeClass */
            $attributeClass = $attribute->getName();
            $classes[$attributeClass] = true;
        }
    }

    /**
     * @param list<ReflectionParameter> $parameters
     * @param array<class-string, true> $classes
     */
    private static function collectParameterAttributeClasses(
        array $parameters,
        array &$classes,
    ): void {
        foreach ($parameters as $parameter) {
            /** @var ReflectionAttribute<object> $attribute */
            foreach ($parameter->getAttributes() as $attribute) {
                $attributeClass = $attribute->getName();

                /** @var class-string $attributeClass */
                $classes[$attributeClass] = true;
            }
        }
    }

    /** @return list<ReflectionProperty> */
    private static function properties(ReflectionClass $class): array
    {
        $properties = $class->getProperties();

        for ($parent = $class->getParentClass(); $parent !== false; $parent = $parent->getParentClass()) {
            foreach ($parent->getProperties(\ReflectionProperty::IS_PRIVATE) as $property) {
                if ($property->getDeclaringClass()->getName() === $parent->getName()) {
                    $properties[] = $property;
                }
            }
        }

        return $properties;
    }

    /** @return list<ReflectionMethod> */
    private static function methods(ReflectionClass $class): array
    {
        $methods = $class->getMethods();

        for ($parent = $class->getParentClass(); $parent !== false; $parent = $parent->getParentClass()) {
            foreach ($parent->getMethods(ReflectionMethod::IS_PRIVATE) as $method) {
                if ($method->getDeclaringClass()->getName() === $parent->getName()) {
                    $methods[] = $method;
                }
            }
        }

        return $methods;
    }

    /**
     * @param list<array{handler: AttributeHandlerInterface, order: int, phase: AttributePhase, priority: int}> $registrations
     * @param list<AttributeInvocation> $before
     * @param list<AttributeInvocation> $after
     */
    private function collect(
        ReflectionClass|ReflectionProperty|ReflectionMethod $target,
        array $registrations,
        array &$before,
        array &$after,
        int &$declarationOrder,
    ): void {
        /** @var ReflectionAttribute<object> $reflectionAttribute */
        foreach ($target->getAttributes() as $attributeIndex => $reflectionAttribute) {
            $attributeClass = $reflectionAttribute->getName();

            foreach ($registrations as $handlerSlot => $registration) {
                $handler = $registration['handler'];
                if (!$handler->supportsAttribute($attributeClass, $target)) {
                    continue;
                }

                $invocation = new AttributeInvocation(
                    handler: $handler,
                    handlerSlot: $handlerSlot,
                    reflectionAttribute: $reflectionAttribute,
                    attributeClass: $attributeClass,
                    attributeIndex: $attributeIndex,
                    target: $target,
                    priority: $registration['priority'],
                    declarationOrder: $declarationOrder++,
                );

                if ($registration['phase'] === AttributePhase::BeforeInstantiation) {
                    $before[] = $invocation;
                } else {
                    $after[] = $invocation;
                }

                // One attribute is owned by the first supporting handler in
                // registry priority order. This keeps dispatch deterministic.
                continue 2;
            }

            ++$declarationOrder;
        }
    }

    /** @param list<AttributeInvocation> $invocations */
    private static function sort(array &$invocations): void
    {
        usort(
            $invocations,
            static fn(AttributeInvocation $left, AttributeInvocation $right): int =>
                $right->priority <=> $left->priority
                ?: $left->declarationOrder <=> $right->declarationOrder,
        );
    }
}

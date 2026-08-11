<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Attribute;

use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use LogicException;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/** Binds class/property/method attributes to the exact owning handlers. */
final class AttributeProcessor
{
    /** @var array<class-string, array{version: int, plan: AttributeExecutionPlan}> */
    private array $cache = [];

    public function __construct(
        public readonly AttributeHandlerRegistry $registry,
    ) {}

    /** @param ReflectionClass<object> $class */
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
     * @param ReflectionClass<object> $class
     * @return array{before: list<AttributeInvocation>, after: list<AttributeInvocation>}
     */
    public function invocations(ReflectionClass $class): array
    {
        $plan = $this->plan($class);

        return [
            'before' => $plan->before,
            'after' => $plan->after,
        ];
    }

    /** @param ReflectionClass<object> $class */
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
     * @param ReflectionClass<object> $class
     * @return list<ReflectionProperty>
     */
    private static function properties(ReflectionClass $class): array
    {
        $properties = $class->getProperties();

        for ($parent = $class->getParentClass(); $parent !== false; $parent = $parent->getParentClass()) {
            foreach ($parent->getProperties(ReflectionProperty::IS_PRIVATE) as $property) {
                if ($property->getDeclaringClass()->getName() === $parent->getName()) {
                    $properties[] = $property;
                }
            }
        }

        return $properties;
    }

    /**
     * @param ReflectionClass<object> $class
     * @return list<ReflectionMethod>
     */
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
     * @param ReflectionClass<object>|ReflectionProperty|ReflectionMethod $target
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
            /** @var class-string $attributeClass */
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

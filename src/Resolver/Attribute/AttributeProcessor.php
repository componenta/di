<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Attribute;

use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Exception\ExceptionInterface;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Internal\BootstrapResolutionGuard;
use Componenta\DI\Internal\CompletedAttributeGuard;
use Componenta\DI\Internal\ObjectAttributeSequence;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Throwable;

/**
 * Executes composed class/property/method attribute handlers.
 * Before instantiation, class policies precede property and method handlers.
 * After instantiation, properties are initialized before class and method hooks.
 * Parameter plans are validated by the same composition model but executed
 * exclusively through AttributeParameterResolver. Runtime handlers receive
 * isolated attribute instances so mutable attribute state cannot leak between
 * object creations or concurrent execution contexts.
 */
final class AttributeProcessor
{
    /**
     * @var array<class-string,array{
     *     revision:int,
     *     hasHandlers:bool,
     *     before:list<ReflectionClass<object>|ReflectionProperty|ReflectionMethod>,
     *     after:list<ReflectionClass<object>|ReflectionProperty|ReflectionMethod>
     * }>
     */
    private array $cache = [];

    public function __construct(
        private readonly AttributeDefinitionRegistry $registry,
        private readonly AttributePlanBuilder $plans,
        private readonly ?BootstrapResolutionGuard $bootstrap = null,
    ) {}

    /**
     * @internal
     * @param ReflectionClass<object> $class
     */
    public function recordBootstrapUse(ReflectionClass $class, AttributePhase $phase): void
    {
        if ($this->bootstrap?->isActive !== true) {
            return;
        }
        $this->bootstrap->recordAttributes($class, $phase);
        foreach (self::properties($class) as $property) {
            $this->bootstrap->recordAttributes($property, $phase);
        }
        foreach (self::methods($class) as $method) {
            $this->bootstrap->recordAttributes($method, $phase);
        }
    }

    /**
     * Records the empty before phase used by the no-handler fast path.
     * @internal
     * @param ReflectionClass<object> $class
     */
    public function skippedBeforePhase(ReflectionClass $class): CompletedAttributeGuard
    {
        $guard = new CompletedAttributeGuard($this->plans);
        foreach ($this->executionPlan($class)['before'] as $target) {
            $guard->record($target, new AttributePlan($target, []), AttributePhase::BeforeInstantiation);
        }
        return $guard;
    }

    /** @param ReflectionClass<object> $class */
    public function prepare(ReflectionClass $class): void
    {
        $this->executionPlan($class);
    }

    /** @param ReflectionClass<object> $class */
    public function hasHandlers(ReflectionClass $class): bool
    {
        $plan = $this->executionPlan($class);
        return $plan['hasHandlers'];
    }

    /** @param ReflectionClass<object> $class */
    public function process(
        ReflectionClass $class,
        AttributePhase $phase,
        ObjectCreationContext $context,
    ): void {
        $this->processTracked($class, $phase, $context);
    }

    /**
     * @internal
     * @param ReflectionClass<object> $class
     */
    public function processTracked(
        ReflectionClass $class,
        AttributePhase $phase,
        ObjectCreationContext $context,
    ): CompletedAttributeGuard {
        if ($phase === AttributePhase::Both) {
            throw new InvalidConfigurationException(
                'AttributeProcessor::process() requires a concrete runtime phase.',
            );
        }

        $plan = $this->executionPlan($class);
        $targets = $phase === AttributePhase::BeforeInstantiation
            ? $plan['before']
            : $plan['after'];

        $sequence = new ObjectAttributeSequence($this->plans, $targets, $phase);
        $completedProperties = [];
        while (($usage = $sequence->next()) !== null) {
            $target = $sequence->target($usage);
            if ($target instanceof ReflectionProperty) {
                $key = spl_object_id($target);
                if (isset($completedProperties[$key])) {
                    throw new AttributeCompositionException(sprintf(
                        'Attribute composition for "%s::$%s" changed after its property handlers completed. Load its attributes before execution.',
                        $target->getDeclaringClass()->getName(),
                        $target->getName(),
                    ));
                }
                $completed = $sequence->completed;
                $context->resolveProperty($target, function () use ($class, $target, $sequence, $context): void {
                    $this->processTarget($class, $target, $sequence, $context);
                });
                if ($sequence->completed > $completed) {
                    $completedProperties[$key] = true;
                }
            } else {
                $this->processTarget($class, $target, $sequence, $context);
            }
        }
        $sequence->recordBootstrapUse($this->bootstrap);
        return $sequence->completionGuard();
    }

    /**
     * @param ReflectionClass<object> $class
     * @param ReflectionClass<object>|ReflectionProperty|ReflectionMethod $target
     */
    private function processTarget(
        ReflectionClass $class,
        ReflectionClass|ReflectionProperty|ReflectionMethod $target,
        ObjectAttributeSequence $sequence,
        ObjectCreationContext $context,
    ): void {
        while (($usage = $sequence->next()) !== null && $sequence->target($usage) === $target) {
            $handler = $usage->definition->handler;
            if (!$handler instanceof AttributeHandlerInterface) {
                throw new InvalidConfigurationException('Object attribute sequence requires an object handler.');
            }
            try {
                $attribute = $sequence->instance($usage);
                if (!$sequence->isCurrent()) {
                    // The next usage can now belong to an earlier target. Return
                    // there before dispatching this handler, retaining its instance.
                    continue;
                }

                $sequence->dispatch($usage);
                $handler->handle($attribute, $target, $context);
                unset($attribute);
            } catch (ExceptionInterface $e) {
                throw $e;
            } catch (Throwable $e) {
                if ($target instanceof ReflectionProperty) {
                    throw ResolutionException::forProperty($target, previous: $e);
                }
                throw ResolutionException::forService($class->getName(), $e);
            }
        }
    }

    /**
     * @param ReflectionClass<object> $class
     * @return array{
     *     revision:int,
     *     hasHandlers:bool,
     *     before:list<ReflectionClass<object>|ReflectionProperty|ReflectionMethod>,
     *     after:list<ReflectionClass<object>|ReflectionProperty|ReflectionMethod>
     * }
     */
    private function executionPlan(ReflectionClass $class): array
    {
        $name = $class->getName();
        $revision = $this->registry->revision;
        $cached = $this->cache[$name] ?? null;
        if ($this->plans->canCachePlans && $cached !== null && $cached['revision'] === $revision) {
            return $cached;
        }

        $properties = self::properties($class);
        $methods = self::methods($class);
        $hasAttributes = static fn(ReflectionClass|ReflectionProperty|ReflectionMethod $target): bool => $target->getAttributes() !== [];
        $before = array_values(array_filter([$class, ...$properties, ...$methods], $hasAttributes));
        $after = array_values(array_filter([...$properties, $class, ...$methods], $hasAttributes));
        $hasHandlers = false;
        foreach ($before as $target) {
            foreach ($this->plans->build($target)->usages as $usage) {
                if ($usage->definition->handler instanceof AttributeHandlerInterface) {
                    $hasHandlers = true;
                }
            }
        }

        $plan = [
            'revision' => $revision,
            'hasHandlers' => $hasHandlers,
            'before' => $before,
            'after' => $after,
        ];
        if ($this->plans->canCachePlans) {
            $this->cache[$name] = $plan;
        }
        return $plan;
    }

    /**
     * @param ReflectionClass<object> $class
     * @return list<ReflectionProperty>
     */
    private static function properties(ReflectionClass $class): array
    {
        /** @var list<ReflectionProperty> $properties */
        $properties = array_values($class->getProperties());
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
        /** @var list<ReflectionMethod> $methods */
        $methods = array_values($class->getMethods());
        for ($parent = $class->getParentClass(); $parent !== false; $parent = $parent->getParentClass()) {
            foreach ($parent->getMethods(ReflectionMethod::IS_PRIVATE) as $method) {
                if ($method->getDeclaringClass()->getName() === $parent->getName()) {
                    $methods[] = $method;
                }
            }
        }
        return $methods;
    }
}

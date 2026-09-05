<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Attribute;

use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Exception\ExceptionInterface;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Internal\BootstrapResolutionGuard;
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
    /** @var array<class-string,array{revision:int,before:list<AttributeUsage>,after:list<AttributeUsage>}> */
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
    public function recordBootstrapUse(ReflectionClass $class): void
    {
        if ($this->bootstrap?->isActive !== true) {
            return;
        }
        $this->bootstrap->recordAttributes($class);
        foreach (self::properties($class) as $property) {
            $this->bootstrap->recordAttributes($property);
        }
        foreach (self::methods($class) as $method) {
            $this->bootstrap->recordAttributes($method);
        }
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
        return $plan['before'] !== [] || $plan['after'] !== [];
    }

    /** @param ReflectionClass<object> $class */
    public function process(
        ReflectionClass $class,
        AttributePhase $phase,
        ObjectCreationContext $context,
    ): void {
        if ($phase === AttributePhase::Both) {
            throw new InvalidConfigurationException(
                'AttributeProcessor::process() requires a concrete runtime phase.',
            );
        }

        $plan = $this->executionPlan($class);
        $usages = $phase === AttributePhase::BeforeInstantiation
            ? $plan['before']
            : $plan['after'];

        $count = count($usages);
        for ($index = 0; $index < $count; ++$index) {
            $usage = $usages[$index];
            if (!$usage->target instanceof ReflectionProperty) {
                $this->processUsage($class, $usage, $context);
                continue;
            }

            $propertyUsages = [$usage];
            while ($index + 1 < $count && $usages[$index + 1]->target === $usage->target) {
                $propertyUsages[] = $usages[++$index];
            }

            $context->resolveProperty($usage->target, function () use ($class, $propertyUsages, $context): void {
                foreach ($propertyUsages as $propertyUsage) {
                    $this->processUsage($class, $propertyUsage, $context);
                }
            });
        }
    }

    /** @param ReflectionClass<object> $class */
    private function processUsage(ReflectionClass $class, AttributeUsage $usage, ObjectCreationContext $context): void
    {
        $handler = $usage->definition->handler;
        if (!$handler instanceof AttributeHandlerInterface) {
            return;
        }

        try {
            $handler->handle($usage->newInstance(), $usage->target, $context);
        } catch (ExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            if ($usage->target instanceof ReflectionProperty) {
                throw ResolutionException::forProperty($usage->target, previous: $e);
            }

            throw ResolutionException::forService($class->getName(), $e);
        }
    }

    /**
     * @param ReflectionClass<object> $class
     * @return array{revision:int,before:list<AttributeUsage>,after:list<AttributeUsage>}
     */
    private function executionPlan(ReflectionClass $class): array
    {
        $name = $class->getName();
        $revision = $this->registry->revision;
        $cached = $this->cache[$name] ?? null;
        if ($cached !== null && $cached['revision'] === $revision) {
            return $cached;
        }

        $before = [];
        $after = [];
        $classAfter = [];

        $this->collect($this->plans->build($class)->usages, $before, $classAfter);
        foreach (self::properties($class) as $property) {
            $this->collect($this->plans->build($property)->usages, $before, $after);
        }
        $after = [...$after, ...$classAfter];
        foreach (self::methods($class) as $method) {
            $this->collect($this->plans->build($method)->usages, $before, $after);
        }

        return $this->cache[$name] = [
            'revision' => $revision,
            'before' => $before,
            'after' => $after,
        ];
    }

    /**
     * @param list<AttributeUsage> $usages
     * @param list<AttributeUsage> $before
     * @param list<AttributeUsage> $after
     */
    private static function collect(array $usages, array &$before, array &$after): void
    {
        foreach ($usages as $usage) {
            if (!$usage->definition->handler instanceof AttributeHandlerInterface) {
                continue;
            }

            $phase = $usage->definition->phase;
            if ($phase === AttributePhase::BeforeInstantiation || $phase === AttributePhase::Both) {
                $before[] = $usage;
            }
            if ($phase === AttributePhase::AfterInstantiation || $phase === AttributePhase::Both) {
                $after[] = $usage;
            }
        }
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

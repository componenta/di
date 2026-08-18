<?php

declare(strict_types=1);

namespace Componenta\DI\Object;

use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Composition\Capability\ConstructorPolicy;
use Componenta\DI\Attribute\Composition\Capability\CreationStrategy as CreationStrategyCapability;
use Componenta\DI\Attribute\Composition\Capability\LifecycleHook;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Attribute\Composition\Capability\ValueTransformer;
use Componenta\DI\Attribute\Handler\ConstructorPolicyHandlerInterface;
use Componenta\DI\Attribute\Handler\CreationStrategyHandlerInterface;
use Componenta\DI\Attribute\Handler\LifecycleHookHandlerInterface;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\ProxyFactoryInterface;
use Componenta\DI\ResolutionContext;
use Componenta\DI\Resolver\Entry\InstanceCreator;
use Componenta\DI\Resolver\Target\PropertyTarget;
use Componenta\DI\Value\ValueContext;
use Componenta\DI\Value\ValuePipeline;
use LogicException;
use ReflectionClass;
use ReflectionProperty;

/** Single runtime object-creation pipeline shared by reflection and compiled entries. */
final readonly class ObjectPipeline
{
    public function __construct(
        private AttributePlanBuilder $plans,
        private InstanceCreator $instances,
        private ValuePipeline $values,
        private ProxyFactoryInterface $proxies,
    ) {}

    /** @param class-string $class */
    public function create(
        string $class,
        ResolutionContext $context = new ResolutionContext(),
    ): object {
        /** @var ReflectionClass<object> $reflection */
        $reflection = new ReflectionClass($class);
        $plan = $this->plans->build($reflection);
        $useConstructor = $this->useConstructor($reflection, $plan, $context);
        $strategy = $this->strategy($reflection, $plan, $context);

        return match ($strategy) {
            CreationStrategy::Eager => $this->eager($reflection, $plan, $context, $useConstructor),
            CreationStrategy::Lazy => $this->lazy($reflection, $plan, $context, $useConstructor),
            CreationStrategy::Proxy => $this->proxy($reflection, $plan, $context, $useConstructor),
        };
    }

    /** @param ReflectionClass<object> $class */
    private function eager(
        ReflectionClass $class,
        AttributePlan $plan,
        ResolutionContext $context,
        bool $useConstructor,
    ): object {
        $entry = $useConstructor
            ? $this->instances->create($class, $context)
            : $class->newInstanceWithoutConstructor();

        $this->populate($entry, $class, $context);
        $this->lifecycle($entry, $class, $plan, $context);

        return $entry;
    }

    /** @param ReflectionClass<object> $class */
    private function lazy(
        ReflectionClass $class,
        AttributePlan $plan,
        ResolutionContext $context,
        bool $useConstructor,
    ): object {
        return $this->proxies->makeLazy(
            $class->getName(),
            function (object $entry) use ($class, $plan, $context, $useConstructor): void {
                if ($useConstructor) {
                    $this->instances->initialize($entry, $class, $context);
                }

                $this->populate($entry, $class, $context);
                $this->lifecycle($entry, $class, $plan, $context);
            },
        );
    }

    /** @param ReflectionClass<object> $class */
    private function proxy(
        ReflectionClass $class,
        AttributePlan $plan,
        ResolutionContext $context,
        bool $useConstructor,
    ): object {
        return $this->proxies->makeProxy(
            $class->getName(),
            fn(object $_proxy): object => $this->eager($class, $plan, $context, $useConstructor),
        );
    }

    /** @param ReflectionClass<object> $class */
    private function useConstructor(
        ReflectionClass $class,
        AttributePlan $plan,
        ResolutionContext $context,
    ): bool {
        $usage = $plan->one(ConstructorPolicy::class);
        if ($usage === null) {
            return true;
        }

        $handler = $usage->definition->handler;
        if (!$handler instanceof ConstructorPolicyHandlerInterface) {
            throw new LogicException(sprintf(
                'Attribute %s declares ConstructorPolicy but its handler does not implement %s.',
                $usage->attribute::class,
                ConstructorPolicyHandlerInterface::class,
            ));
        }

        return $handler->useConstructor($usage->attribute, $class, $context);
    }

    /** @param ReflectionClass<object> $class */
    private function strategy(
        ReflectionClass $class,
        AttributePlan $plan,
        ResolutionContext $context,
    ): CreationStrategy {
        $usage = $plan->one(CreationStrategyCapability::class);
        if ($usage === null) {
            return CreationStrategy::Eager;
        }

        $handler = $usage->definition->handler;
        if (!$handler instanceof CreationStrategyHandlerInterface) {
            throw new LogicException(sprintf(
                'Attribute %s declares CreationStrategy but its handler does not implement %s.',
                $usage->attribute::class,
                CreationStrategyHandlerInterface::class,
            ));
        }

        return $handler->strategy($usage->attribute, $class, $context);
    }

    /** @param ReflectionClass<object> $class */
    private function populate(object $entry, ReflectionClass $class, ResolutionContext $context): void
    {
        foreach (self::properties($class) as $property) {
            if ($property->isPromoted()) {
                continue;
            }

            $plan = $this->plans->build($property);
            if (!$plan->has(ValueProvider::class) && !$plan->has(ValueTransformer::class)) {
                continue;
            }

            if ($property->isStatic()) {
                throw ResolutionException::forProperty($property, reason: 'DI value attributes are not supported on static properties');
            }

            if ($property->isReadOnly() && $property->isInitialized($entry)) {
                throw ResolutionException::forProperty($property, reason: 'an initialized readonly property cannot be populated by DI');
            }

            $target = new PropertyTarget($property);
            $value = $this->values->resolve(
                $target,
                $plan,
                new ValueContext($context, object: $entry),
            );
            $property->setValue($entry, $value);
        }
    }

    /** @param ReflectionClass<object> $class */
    private function lifecycle(
        object $entry,
        ReflectionClass $class,
        AttributePlan $plan,
        ResolutionContext $context,
    ): void {
        foreach ($plan->all(LifecycleHook::class) as $usage) {
            $handler = $usage->definition->handler;
            if (!$handler instanceof LifecycleHookHandlerInterface) {
                throw new LogicException(sprintf(
                    'Attribute %s declares LifecycleHook but its handler does not implement %s.',
                    $usage->attribute::class,
                    LifecycleHookHandlerInterface::class,
                ));
            }

            $handler->run($usage->attribute, $entry, $class, $context);
        }
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
}

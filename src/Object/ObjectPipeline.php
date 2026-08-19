<?php

declare(strict_types=1);

namespace Componenta\DI\Object;

use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
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
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Attribute\AttributeProcessor;
use Componenta\DI\Resolver\Entry\InstanceCreator;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Target\PropertyTarget;
use Componenta\DI\Value\ValueContext;
use Componenta\DI\Value\ValuePipeline;
use LogicException;
use ReflectionClass;
use ReflectionProperty;

/** Single runtime object-creation pipeline shared by reflection and compiled entries. */
final class ObjectPipeline
{
    /** @var array<class-string, ObjectMetadata> */
    private array $metadata = [];
    private int $metadataRevision = -1;
    private readonly ?AttributeProcessor $attributeProcessor;

    public function __construct(
        private readonly AttributePlanBuilder $plans,
        private readonly InstanceCreator $instances,
        private readonly ValuePipeline $values,
        private readonly ProxyFactoryInterface $proxies,
        private readonly ?AttributeDefinitionRegistry $registry = null,
    ) {
        $this->attributeProcessor = $registry === null
            ? null
            : new AttributeProcessor($registry, $plans);
    }

    /** @param class-string $class */
    public function prepare(string $class): void
    {
        $metadata = $this->metadata($class);
        $this->attributeProcessor?->prepare($metadata->class);
    }

    /**
     * @param class-string $class
     * @param array<string|int, mixed> $params
     */
    public function create(string $class, array $params = []): object
    {
        $metadata = $this->metadata($class);
        $creation = new ObjectCreationContext($metadata->class, $params);
        $this->attributeProcessor?->process(
            $metadata->class,
            AttributePhase::BeforeInstantiation,
            $creation,
        );

        $legacyContext = new ResolutionContext(explicit: $params);
        $useConstructor = $this->legacyUseConstructor(
            $metadata->class,
            $metadata->classPlan,
            $legacyContext,
            $creation->constructorEnabled,
        );
        $strategy = $this->legacyStrategy(
            $metadata->class,
            $metadata->classPlan,
            $legacyContext,
            $creation->strategy,
        );

        return match ($strategy) {
            CreationStrategy::Eager => $this->eager($metadata, $params, $creation, $legacyContext, $useConstructor),
            CreationStrategy::Lazy => $this->lazy($metadata, $params, $creation, $legacyContext, $useConstructor),
            CreationStrategy::Proxy => $this->proxy($metadata, $params, $creation, $legacyContext, $useConstructor),
        };
    }

    /** @param class-string $class */
    private function metadata(string $class): ObjectMetadata
    {
        $revision = $this->registry?->revision ?? 0;
        if ($revision !== $this->metadataRevision) {
            $this->metadata = [];
            $this->metadataRevision = $revision;
        }

        if (isset($this->metadata[$class])) {
            return $this->metadata[$class];
        }

        /** @var ReflectionClass<object> $reflection */
        $reflection = new ReflectionClass($class);
        $classPlan = $this->plans->build($reflection);

        $constructor = $reflection->getConstructor();
        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $parameter) {
                $this->plans->build($parameter);
            }
        }

        $propertyPlans = [];
        foreach (self::properties($reflection) as $property) {
            if ($property->isPromoted()) {
                continue;
            }

            $plan = $this->plans->build($property);
            if (!$plan->has(ValueProvider::class) && !$plan->has(ValueTransformer::class)) {
                continue;
            }
            if ($property->isStatic()) {
                throw ResolutionException::forProperty(
                    $property,
                    reason: 'DI value attributes are not supported on static properties',
                );
            }

            $propertyPlans[] = new PropertyValuePlan(
                $property,
                new PropertyTarget($property),
                $plan,
            );
        }

        $metadata = new ObjectMetadata($reflection, $classPlan, $propertyPlans);
        $this->attributeProcessor?->prepare($reflection);
        return $this->metadata[$class] = $metadata;
    }

    /** @param array<string|int, mixed> $params */
    private function eager(
        ObjectMetadata $metadata,
        array $params,
        ObjectCreationContext $creation,
        ResolutionContext $legacyContext,
        bool $useConstructor,
    ): object {
        $entry = $useConstructor
            ? $this->instances->create($metadata->class, $params)
            : $metadata->class->newInstanceWithoutConstructor();

        $creation->initialize($entry);
        $this->populateLegacy($entry, $metadata, $legacyContext);
        $this->lifecycleLegacy($entry, $metadata->class, $metadata->classPlan, $legacyContext);
        $this->attributeProcessor?->process(
            $metadata->class,
            AttributePhase::AfterInstantiation,
            $creation,
        );
        return $entry;
    }

    /** @param array<string|int, mixed> $params */
    private function lazy(
        ObjectMetadata $metadata,
        array $params,
        ObjectCreationContext $creation,
        ResolutionContext $legacyContext,
        bool $useConstructor,
    ): object {
        return $this->proxies->makeLazy(
            $metadata->class->getName(),
            function (object $entry) use ($metadata, $params, $creation, $legacyContext, $useConstructor): void {
                if ($useConstructor) {
                    $this->instances->initialize($entry, $metadata->class, $params);
                }

                $attempt = $creation->freshAttempt();
                $attempt->initialize($entry);
                $this->populateLegacy($entry, $metadata, $legacyContext);
                $this->lifecycleLegacy($entry, $metadata->class, $metadata->classPlan, $legacyContext);
                $this->attributeProcessor?->process(
                    $metadata->class,
                    AttributePhase::AfterInstantiation,
                    $attempt,
                );
            },
        );
    }

    /** @param array<string|int, mixed> $params */
    private function proxy(
        ObjectMetadata $metadata,
        array $params,
        ObjectCreationContext $creation,
        ResolutionContext $legacyContext,
        bool $useConstructor,
    ): object {
        return $this->proxies->makeProxy(
            $metadata->class->getName(),
            fn(object $_proxy): object => $this->eager(
                $metadata,
                $params,
                $creation->freshAttempt(),
                $legacyContext,
                $useConstructor,
            ),
        );
    }

    /** @param ReflectionClass<object> $class */
    private function legacyUseConstructor(
        ReflectionClass $class,
        AttributePlan $plan,
        ResolutionContext $context,
        bool $default,
    ): bool {
        $usage = $plan->one(ConstructorPolicy::class);
        if ($usage === null || $usage->definition->handler instanceof \Componenta\DI\Resolver\Attribute\AttributeHandlerInterface) {
            return $default;
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
    private function legacyStrategy(
        ReflectionClass $class,
        AttributePlan $plan,
        ResolutionContext $context,
        CreationStrategy $default,
    ): CreationStrategy {
        $usage = $plan->one(CreationStrategyCapability::class);
        if ($usage === null || $usage->definition->handler instanceof \Componenta\DI\Resolver\Attribute\AttributeHandlerInterface) {
            return $default;
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

    private function populateLegacy(object $entry, ObjectMetadata $metadata, ResolutionContext $context): void
    {
        foreach ($metadata->properties as $propertyPlan) {
            if ($propertyPlan->plan->usages !== []) {
                $allGeneric = true;
                foreach ($propertyPlan->plan->usages as $usage) {
                    if (!$usage->definition->handler instanceof \Componenta\DI\Resolver\Attribute\AttributeHandlerInterface) {
                        $allGeneric = false;
                        break;
                    }
                }
                if ($allGeneric) {
                    continue;
                }
            }

            $property = $propertyPlan->property;
            if ($property->isReadOnly() && $property->isInitialized($entry)) {
                throw ResolutionException::forProperty(
                    $property,
                    reason: 'an initialized readonly property cannot be populated by DI',
                );
            }

            $value = $this->values->resolve(
                $propertyPlan->target,
                $propertyPlan->plan,
                new ValueContext($context, object: $entry),
            );
            $property->setValue($entry, $value);
        }
    }

    /** @param ReflectionClass<object> $class */
    private function lifecycleLegacy(
        object $entry,
        ReflectionClass $class,
        AttributePlan $plan,
        ResolutionContext $context,
    ): void {
        foreach ($plan->all(LifecycleHook::class) as $usage) {
            if ($usage->definition->handler instanceof \Componenta\DI\Resolver\Attribute\AttributeHandlerInterface) {
                continue;
            }
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

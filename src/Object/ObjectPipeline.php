<?php

declare(strict_types=1);

namespace Componenta\DI\Object;

use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Composition\Capability\ConstructorPolicy;
use Componenta\DI\Internal\ResolutionMetadata;
use Componenta\DI\Internal\Resolver\Entry\ObjectResolutionParameterStore;
use Componenta\DI\Internal\Resolver\Parameter\PreparedParameterPlan;
use Componenta\DI\Internal\Resolver\Parameter\Request\MappedRequestParameterSourceGuard;
use Componenta\DI\ProxyFactoryInterface;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Attribute\AttributeProcessor;
use Componenta\DI\Resolver\Entry\InstanceCreator;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Resolver\Target\ParameterTarget;
use ReflectionClass;

use function Componenta\DI\Internal\is_entry_class_eligible;

/** Single object-creation runtime shared by reflection and compiled entries. */
final class ObjectPipeline
{
    /** @var array<class-string,ObjectMetadata> */
    private array $metadata = [];
    /** @var array<class-string,PreparedParameterPlan> */
    private array $constructorPlans = [];
    private int $metadataRevision = -1;
    private readonly AttributeProcessor $attributes;

    public function __construct(
        private readonly AttributePlanBuilder $plans,
        private readonly InstanceCreator $instances,
        private readonly ProxyFactoryInterface $proxies,
        private readonly AttributeDefinitionRegistry $registry,
        private readonly ObjectResolutionParameterStore $resolutionParameters,
        ?AttributeProcessor $attributes = null,
    ) {
        $this->attributes = $attributes ?? new AttributeProcessor($registry, $plans);
    }

    public function parameters(): ParametersResolver
    {
        return $this->instances->parameters();
    }

    /** @param class-string|ReflectionClass<object> $class */
    public function prepare(string|ReflectionClass $class): void
    {
        $this->metadata($class);
    }

    /** @param class-string|ReflectionClass<object> $class */
    public function canCreate(string|ReflectionClass $class): bool
    {
        $metadata = $this->metadata($class);
        if (!is_entry_class_eligible($metadata->class)) {
            return false;
        }
        if ($metadata->class->isInstantiable()) {
            return true;
        }

        foreach ($metadata->classPlan->all(ConstructorPolicy::class) as $usage) {
            $definition = $usage->definition;
            if (!$definition->handler instanceof AttributeHandlerInterface) {
                continue;
            }
            if ($definition->phase === AttributePhase::BeforeInstantiation
                || $definition->phase === AttributePhase::Both
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param class-string|ReflectionClass<object> $class
     * @return list<ParameterTarget>
     */
    public function constructorTargets(string|ReflectionClass $class): array
    {
        return $this->metadata($class)->constructorTargets;
    }

    /**
     * @param class-string|ReflectionClass<object> $class
     * @param array<string|int,mixed> $params
     */
    public function create(string|ReflectionClass $class, array $params = []): object
    {
        $metadata = $this->metadata($class);
        $constructorPlan = $this->constructorPlan($metadata);
        if (!$metadata->hasAttributeHandlers) {
            return $this->instances->createPrepared(
                $metadata->class,
                $metadata->constructor,
                $constructorPlan,
                $params,
            );
        }

        /** @var class-string $className */
        $className = $metadata->class->getName();
        MappedRequestParameterSourceGuard::assertClassContextNoConflicts($className, $params);

        $creation = new ObjectCreationContext(
            $metadata->class,
            ResolutionMetadata::publicParameters($params),
        );
        $this->resolutionParameters->attach($creation, $params);

        $this->attributes->process(
            $metadata->class,
            AttributePhase::BeforeInstantiation,
            $creation,
        );

        return match ($creation->strategy) {
            CreationStrategy::Eager => $this->eager($metadata, $constructorPlan, $creation),
            CreationStrategy::Lazy => $this->lazy($metadata, $constructorPlan, $creation),
            CreationStrategy::Proxy => $this->proxy($metadata, $constructorPlan, $creation),
        };
    }

    /** @param class-string|ReflectionClass<object> $class */
    private function metadata(string|ReflectionClass $class): ObjectMetadata
    {
        $revision = $this->registry->revision;
        if ($revision !== $this->metadataRevision) {
            $this->metadata = [];
            $this->constructorPlans = [];
            $this->metadataRevision = $revision;
        }

        $name = $class instanceof ReflectionClass
            ? $class->getName()
            : ltrim($class, '\\');
        if (isset($this->metadata[$name])) {
            return $this->metadata[$name];
        }

        /** @var ReflectionClass<object> $reflection */
        $reflection = $class instanceof ReflectionClass
            ? $class
            : new ReflectionClass($class);
        $name = $reflection->getName();
        $classPlan = $this->plans->build($reflection);
        $constructor = $reflection->getConstructor();
        $constructorTargets = $constructor === null
            ? []
            : $this->instances->targets($constructor);

        foreach ($constructorTargets as $target) {
            $this->plans->build($target->reflection);
        }

        $hasAttributeHandlers = $this->attributes->hasHandlers($reflection);

        return $this->metadata[$name] = new ObjectMetadata(
            $reflection,
            $classPlan,
            $constructor,
            $constructorTargets,
            $hasAttributeHandlers,
        );
    }

    private function constructorPlan(ObjectMetadata $metadata): PreparedParameterPlan
    {
        $name = $metadata->class->getName();
        $cached = $this->constructorPlans[$name] ?? null;
        $parameters = $this->parameters();

        if ($cached !== null && $parameters->isCurrentPlan($cached)) {
            return $cached;
        }

        $plan = $parameters->prepareTargets($metadata->constructorTargets);
        if ($parameters->isSealed) {
            $this->constructorPlans[$name] = $plan;
        }

        return $plan;
    }

    private function eager(
        ObjectMetadata $metadata,
        PreparedParameterPlan $constructorPlan,
        ObjectCreationContext $creation,
    ): object {
        $entry = $creation->constructorEnabled
            ? $this->instances->createPrepared(
                $metadata->class,
                $metadata->constructor,
                $constructorPlan,
                $this->resolutionParameters->get($creation),
            )
            : $metadata->class->newInstanceWithoutConstructor();

        $creation->initialize($entry);
        $this->attributes->process(
            $metadata->class,
            AttributePhase::AfterInstantiation,
            $creation,
        );

        return $entry;
    }

    private function lazy(
        ObjectMetadata $metadata,
        PreparedParameterPlan $constructorPlan,
        ObjectCreationContext $creation,
    ): object {
        return $this->proxies->makeLazy(
            $metadata->class->getName(),
            function (object $entry) use ($metadata, $constructorPlan, $creation): void {
                $attempt = $this->freshAttempt($creation);
                if ($attempt->constructorEnabled) {
                    $this->instances->initializePrepared(
                        $entry,
                        $metadata->constructor,
                        $constructorPlan,
                        $this->resolutionParameters->get($attempt),
                    );
                }

                $attempt->initialize($entry);
                $this->attributes->process(
                    $metadata->class,
                    AttributePhase::AfterInstantiation,
                    $attempt,
                );
            },
        );
    }

    private function proxy(
        ObjectMetadata $metadata,
        PreparedParameterPlan $constructorPlan,
        ObjectCreationContext $creation,
    ): object {
        return $this->proxies->makeProxy(
            $metadata->class->getName(),
            fn(object $_proxy): object => $this->eager(
                $metadata,
                $constructorPlan,
                $this->freshAttempt($creation),
            ),
        );
    }

    private function freshAttempt(ObjectCreationContext $creation): ObjectCreationContext
    {
        $attempt = $creation->freshAttempt();
        $this->resolutionParameters->copy($creation, $attempt);
        return $attempt;
    }
}

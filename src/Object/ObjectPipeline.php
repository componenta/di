<?php

declare(strict_types=1);

namespace Componenta\DI\Object;

use Closure;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Composition\Capability\ConstructorPolicy;
use Componenta\DI\Internal\CompletedAttributeGuard;
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
use Throwable;

use function Componenta\DI\Internal\is_entry_class_eligible;

/** Single object-creation runtime shared by every entry resolver. */
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
     * @param Closure(object):void|null $configure Runs after constructor and attribute initialization.
     * @param Closure():array<string|int,mixed>|null $resolveParameters Materializes declared parameter sources on demand.
     */
    public function create(
        string|ReflectionClass $class,
        array $params = [],
        ?Closure $configure = null,
        ?Closure $resolveParameters = null,
    ): object {
        $metadata = $this->metadata($class);
        if (!$metadata->hasAttributeHandlers) {
            $completed = $this->attributes->skippedBeforePhase($metadata->class);
            $this->attributes->recordBootstrapUse($metadata->class, AttributePhase::BeforeInstantiation);
            $parameters = $resolveParameters === null ? $params : $resolveParameters();
            $entry = $this->instances->createPrepared(
                $metadata->class,
                $metadata->constructor,
                $this->constructorPlan($metadata),
                $parameters,
                $completed->assertUnchanged(...),
            );
            $completed->assertUnchanged();
            if ($this->attributes->hasHandlers($metadata->class)) {
                $creation = new ObjectCreationContext(
                    $metadata->class,
                    ResolutionMetadata::publicParameters($parameters),
                );
                $this->resolutionParameters->attach($creation, $parameters);
                $creation->initialize($entry);
                $this->attributes->process($metadata->class, AttributePhase::AfterInstantiation, $creation);
            } else {
                $this->attributes->recordBootstrapUse($metadata->class, AttributePhase::AfterInstantiation);
            }
            $completed->assertUnchanged();
            $configure?->__invoke($entry);
            $completed->assertUnchanged();
            return $entry;
        }

        /** @var class-string $className */
        $className = $metadata->class->getName();
        MappedRequestParameterSourceGuard::assertClassContextNoConflicts($className, $params);

        $parameterSource = $resolveParameters === null
            ? $params
            : self::parameterSource($className, $resolveParameters);

        $creation = new ObjectCreationContext(
            $metadata->class,
            $parameterSource instanceof Closure
                ? static fn(): array => ResolutionMetadata::publicParameters($parameterSource())
                : ResolutionMetadata::publicParameters($parameterSource),
        );
        $this->resolutionParameters->attach($creation, $parameterSource);

        $completed = $this->attributes->processTracked(
            $metadata->class,
            AttributePhase::BeforeInstantiation,
            $creation,
        );

        $constructorPlan = $creation->constructorEnabled
            ? $this->constructorPlan($metadata)
            : $this->parameters()->prepareTargets([]);

        return match ($creation->strategy) {
            CreationStrategy::Eager => $this->eager($metadata, $constructorPlan, $creation, $completed, $configure),
            CreationStrategy::Lazy => $this->lazy($metadata, $constructorPlan, $creation, $completed, $configure, $resolveParameters),
            CreationStrategy::Proxy => $this->proxy($metadata, $constructorPlan, $creation, $completed, $configure, $resolveParameters),
        };
    }

    /** @param class-string|ReflectionClass<object> $class */
    private function metadata(string|ReflectionClass $class): ObjectMetadata
    {
        while (true) {
            $revision = $this->registry->revision;
            if ($revision !== $this->metadataRevision) {
                $this->metadata = [];
                $this->constructorPlans = [];
                $this->metadataRevision = $revision;
            }

            $name = $class instanceof ReflectionClass
                ? $class->getName()
                : ltrim($class, '\\');
            if ($this->plans->canCachePlans && isset($this->metadata[$name])) {
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
            if ($this->registry->revision !== $revision) {
                // Member discovery can load attributes used by an earlier target.
                // Only publish metadata assembled from one registry revision.
                continue;
            }

            $metadata = new ObjectMetadata(
                $reflection,
                $classPlan,
                $constructor,
                $constructorTargets,
                $hasAttributeHandlers,
            );
            if ($this->plans->canCachePlans) {
                $this->metadata[$name] = $metadata;
            }
            return $metadata;
        }
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
        if ($parameters->canCachePlans) {
            $this->constructorPlans[$name] = $plan;
        }

        return $plan;
    }

    /** @param Closure(object):void|null $configure */
    private function eager(
        ObjectMetadata $metadata,
        PreparedParameterPlan $constructorPlan,
        ObjectCreationContext $creation,
        CompletedAttributeGuard $completed,
        ?Closure $configure,
    ): object {
        $completed->assertUnchanged();
        $entry = $creation->constructorEnabled
            ? $this->instances->createPrepared(
                $metadata->class,
                $metadata->constructor,
                $constructorPlan,
                $this->resolutionParameters->get($creation),
                $completed->assertUnchanged(...),
            )
            : $metadata->class->newInstanceWithoutConstructor();

        $completed->assertUnchanged();
        $creation->initialize($entry);
        $this->attributes->process(
            $metadata->class,
            AttributePhase::AfterInstantiation,
            $creation,
        );
        $completed->assertUnchanged();
        $configure?->__invoke($entry);
        $completed->assertUnchanged();

        return $entry;
    }

    /**
     * @param Closure(object):void|null $configure
     * @param (Closure():array<string|int,mixed>)|null $resolveParameters
     */
    private function lazy(
        ObjectMetadata $metadata,
        PreparedParameterPlan $constructorPlan,
        ObjectCreationContext $creation,
        CompletedAttributeGuard $completed,
        ?Closure $configure,
        ?Closure $resolveParameters,
    ): object {
        return $this->proxies->makeLazy(
            $metadata->class->getName(),
            function (object $entry) use ($metadata, $constructorPlan, &$creation, $completed, $configure, $resolveParameters): void {
                $attempt = $this->freshAttempt($creation);
                try {
                    $completed->assertUnchanged();
                    if ($attempt->constructorEnabled) {
                        $this->instances->initializePrepared(
                            $entry,
                            $metadata->constructor,
                            $constructorPlan,
                            $this->resolutionParameters->get($attempt),
                            $completed->assertUnchanged(...),
                        );
                    }

                    $completed->assertUnchanged();
                    $attempt->initialize($entry);
                    $this->attributes->process(
                        $metadata->class,
                        AttributePhase::AfterInstantiation,
                        $attempt,
                    );
                    $completed->assertUnchanged();
                    $configure?->__invoke($entry);
                    $completed->assertUnchanged();
                } catch (Throwable $exception) {
                    $creation = $this->freshAttempt($creation, $resolveParameters);
                    throw $exception;
                }
            },
        );
    }

    /**
     * @param Closure(object):void|null $configure
     * @param (Closure():array<string|int,mixed>)|null $resolveParameters
     */
    private function proxy(
        ObjectMetadata $metadata,
        PreparedParameterPlan $constructorPlan,
        ObjectCreationContext $creation,
        CompletedAttributeGuard $completed,
        ?Closure $configure,
        ?Closure $resolveParameters,
    ): object {
        return $this->proxies->makeProxy(
            $metadata->class->getName(),
            function (object $_proxy) use ($metadata, $constructorPlan, &$creation, $completed, $configure, $resolveParameters): object {
                try {
                    return $this->eager(
                        $metadata,
                        $constructorPlan,
                        $this->freshAttempt($creation),
                        $completed,
                        $configure,
                    );
                } catch (Throwable $exception) {
                    $creation = $this->freshAttempt($creation, $resolveParameters);
                    throw $exception;
                }
            },
        );
    }

    /** @param (Closure():array<string|int,mixed>)|null $resolveParameters */
    private function freshAttempt(
        ObjectCreationContext $creation,
        ?Closure $resolveParameters = null,
    ): ObjectCreationContext {
        if ($resolveParameters !== null) {
            $source = self::parameterSource($creation->class->getName(), $resolveParameters);
            $attempt = $creation->freshAttempt(
                static fn(): array => ResolutionMetadata::publicParameters($source()),
            );
            $this->resolutionParameters->attach($attempt, $source);
            return $attempt;
        }

        $attempt = $creation->freshAttempt();
        $this->resolutionParameters->copy($creation, $attempt);
        return $attempt;
    }

    /**
     * @param class-string $class
     * @param Closure():array<string|int,mixed> $resolveParameters
     * @return Closure():array<string|int,mixed>
     */
    private static function parameterSource(string $class, Closure $resolveParameters): Closure
    {
        $resolved = null;
        return static function () use ($resolveParameters, $class, &$resolved): array {
            if ($resolved === null) {
                $parameters = $resolveParameters();
                MappedRequestParameterSourceGuard::assertClassContextNoConflicts($class, $parameters);
                $resolved = $parameters;
            }

            return $resolved;
        };
    }
}

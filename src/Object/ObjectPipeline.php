<?php

declare(strict_types=1);

namespace Componenta\DI\Object;

use Closure;
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
        $this->attributes->recordBootstrapUse($metadata->class);
        if (!$metadata->hasAttributeHandlers) {
            $entry = $this->instances->createPrepared(
                $metadata->class,
                $metadata->constructor,
                $this->constructorPlan($metadata),
                $resolveParameters === null ? $params : $resolveParameters(),
            );
            $configure?->__invoke($entry);
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

        $this->attributes->process(
            $metadata->class,
            AttributePhase::BeforeInstantiation,
            $creation,
        );

        $constructorPlan = $creation->constructorEnabled
            ? $this->constructorPlan($metadata)
            : $this->parameters()->prepareTargets([]);

        return match ($creation->strategy) {
            CreationStrategy::Eager => $this->eager($metadata, $constructorPlan, $creation, $configure),
            CreationStrategy::Lazy => $this->lazy($metadata, $constructorPlan, $creation, $configure, $resolveParameters),
            CreationStrategy::Proxy => $this->proxy($metadata, $constructorPlan, $creation, $configure, $resolveParameters),
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

    /** @param Closure(object):void|null $configure */
    private function eager(
        ObjectMetadata $metadata,
        PreparedParameterPlan $constructorPlan,
        ObjectCreationContext $creation,
        ?Closure $configure,
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
        $configure?->__invoke($entry);

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
        ?Closure $configure,
        ?Closure $resolveParameters,
    ): object {
        return $this->proxies->makeLazy(
            $metadata->class->getName(),
            function (object $entry) use ($metadata, $constructorPlan, &$creation, $configure, $resolveParameters): void {
                $attempt = $this->freshAttempt($creation);
                try {
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
                    $configure?->__invoke($entry);
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
        ?Closure $configure,
        ?Closure $resolveParameters,
    ): object {
        return $this->proxies->makeProxy(
            $metadata->class->getName(),
            function (object $_proxy) use ($metadata, $constructorPlan, &$creation, $configure, $resolveParameters): object {
                try {
                    return $this->eager(
                        $metadata,
                        $constructorPlan,
                        $this->freshAttempt($creation),
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

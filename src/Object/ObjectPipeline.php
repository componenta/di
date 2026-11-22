<?php

declare(strict_types=1);

namespace Componenta\DI\Object;

use Closure;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Composition\Capability\ConstructorPolicy;
use Componenta\DI\Internal\CompletedAttributeGuard;
use Componenta\DI\Internal\ResolutionMetadata;
use Componenta\DI\Internal\Resolver\Entry\ConstructorInvocation;
use Componenta\DI\Internal\Resolver\Entry\ObjectCreationAttempt;
use Componenta\DI\Internal\Resolver\Entry\ObjectMetadata;
use Componenta\DI\Internal\Resolver\Entry\ObjectPlans;
use Componenta\DI\Internal\Resolver\Entry\ObjectResolutionParameterStore;
use Componenta\DI\Internal\Resolver\Parameter\PreparedParameterPlan;
use Componenta\DI\Internal\Resolver\Parameter\Request\MappedRequestContext;
use Componenta\DI\Internal\Resolver\Parameter\Request\MappedRequestParameterSourceGuard;
use Componenta\DI\ProxyFactoryInterface;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Attribute\AttributeProcessor;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Resolver\Target\ParameterTarget;
use ReflectionClass;

use function Componenta\DI\Internal\is_entry_class_eligible;

/** Single object-creation runtime shared by every entry resolver. */
final class ObjectPipeline
{
    private readonly ObjectPlans $objectPlans;
    private readonly AttributeProcessor $attributes;

    public function __construct(
        AttributePlanBuilder $plans,
        private readonly ParametersResolver $parameters,
        private readonly ProxyFactoryInterface $proxies,
        AttributeDefinitionRegistry $registry,
        private readonly ObjectResolutionParameterStore $resolutionParameters,
        ?AttributeProcessor $attributes = null,
    ) {
        $this->attributes = $attributes ?? new AttributeProcessor($registry, $plans);
        $this->objectPlans = new ObjectPlans($plans, $parameters, $registry, $this->attributes);
    }

    /**
     * @template T of object
     * @param class-string<T>|ReflectionClass<T> $class
     */
    public function prepare(string|ReflectionClass $class): void
    {
        $this->objectPlans->metadata($class);
    }

    /**
     * @template T of object
     * @param class-string<T>|ReflectionClass<T> $class
     */
    public function canCreate(string|ReflectionClass $class): bool
    {
        $metadata = $this->objectPlans->metadata($class);
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
     * @template T of object
     * @param class-string<T>|ReflectionClass<T> $class
     * @return list<ParameterTarget>
     */
    public function constructorTargets(string|ReflectionClass $class): array
    {
        return $this->objectPlans->metadata($class)->constructorTargets;
    }

    /**
     * @template T of object
     * @param class-string<T>|ReflectionClass<T> $class
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
        $metadata = $this->objectPlans->metadata($class);
        if (!$metadata->hasAttributeHandlers) {
            $completed = $this->attributes->skippedBeforePhase($metadata->class);
            $this->attributes->recordBootstrapUse($metadata->class, AttributePhase::BeforeInstantiation);
            $parameters = $resolveParameters === null ? $params : $resolveParameters();
            $entry = ConstructorInvocation::create(
                $this->parameters,
                $metadata->class,
                $metadata->constructor,
                $this->objectPlans->constructorPlan($metadata),
                $parameters,
                $completed->assertUnchanged(...),
                $constructorProof,
            );
            if ($constructorProof !== null) {
                $completed = $completed->with($constructorProof);
            }
            $completed->assertUnchanged();
            if ($this->attributes->hasHandlers($metadata->class)) {
                $attempt = ObjectCreationContext::begin(
                    $metadata->class,
                    ResolutionMetadata::publicParameters($parameters),
                    $this->parameterReadGuard($metadata, $parameters),
                );
                $creation = $attempt->context;
                $this->resolutionParameters->attach($creation, $parameters);
                $attempt->initialize($entry);
                $after = $this->attributes->processTracked($metadata->class, AttributePhase::AfterInstantiation, $creation);
            } else {
                $this->attributes->recordBootstrapUse($metadata->class, AttributePhase::AfterInstantiation);
                $after = $this->attributes->skippedAfterPhase($metadata->class);
            }
            $completed = $completed->with($after);
            $completed->assertUnchanged();
            $configure?->__invoke($entry);
            $completed->assertUnchanged();
            $constructorProof?->assertParameterSourcesUnchanged();
            if (isset($creation)) {
                $this->resolutionParameters->assertSetupSourcesUnchanged($creation);
            }
            return $entry;
        }

        /** @var class-string $className */
        $className = $metadata->class->getName();
        MappedRequestParameterSourceGuard::assertClassContextNoConflicts($className, $params);

        $parameterSource = $resolveParameters === null
            ? $params
            : self::parameterSource($className, $resolveParameters);

        $attempt = ObjectCreationContext::begin(
            $metadata->class,
            $parameterSource instanceof Closure
                ? static fn(): array => ResolutionMetadata::publicParameters($parameterSource())
                : ResolutionMetadata::publicParameters($parameterSource),
            $this->parameterReadGuard($metadata, $parameterSource),
        );
        $creation = $attempt->context;
        $this->resolutionParameters->attach($creation, $parameterSource);

        $completed = $this->attributes->processTracked(
            $metadata->class,
            AttributePhase::BeforeInstantiation,
            $creation,
        );

        $constructorPlan = $creation->constructorEnabled
            ? $this->objectPlans->constructorPlan($metadata)
            : $this->parameters->prepareTargets([]);

        return match ($creation->strategy) {
            CreationStrategy::Eager => $this->eager($metadata, $constructorPlan, $attempt, $completed, $configure),
            CreationStrategy::Lazy => $this->lazy($metadata, $constructorPlan, $attempt, $completed, $configure, $resolveParameters),
            CreationStrategy::Proxy => $this->proxy($metadata, $constructorPlan, $attempt, $completed, $configure, $resolveParameters),
        };
    }

    /** @param Closure(object):void|null $configure */
    private function eager(
        ObjectMetadata $metadata,
        PreparedParameterPlan $constructorPlan,
        ObjectCreationAttempt $attempt,
        CompletedAttributeGuard $completed,
        ?Closure $configure,
    ): object {
        $creation = $attempt->context;
        $completed->assertUnchanged();
        $constructorProof = null;
        $entry = $creation->constructorEnabled
            ? ConstructorInvocation::create(
                $this->parameters,
                $metadata->class,
                $metadata->constructor,
                $constructorPlan,
                $this->resolutionParameters->get($creation),
                $completed->assertUnchanged(...),
                $constructorProof,
            )
            : $metadata->class->newInstanceWithoutConstructor();

        if ($constructorProof !== null) {
            $completed = $completed->with($constructorProof);
        }
        $completed->assertUnchanged();
        $attempt->initialize($entry);
        $after = $this->attributes->processTracked(
            $metadata->class,
            AttributePhase::AfterInstantiation,
            $creation,
        );
        $completed = $completed->with($after);
        $completed->assertUnchanged();
        $configure?->__invoke($entry);
        $completed->assertUnchanged();
        $constructorProof?->assertParameterSourcesUnchanged();
        $this->resolutionParameters->assertSetupSourcesUnchanged($creation);

        return $entry;
    }

    /**
     * @param Closure(object):void|null $configure
     * @param (Closure():array<string|int,mixed>)|null $resolveParameters
     */
    private function lazy(
        ObjectMetadata $metadata,
        PreparedParameterPlan $constructorPlan,
        ObjectCreationAttempt $creation,
        CompletedAttributeGuard $completed,
        ?Closure $configure,
        ?Closure $resolveParameters,
    ): object {
        return $this->proxies->makeLazy(
            $metadata->class->getName(),
            function (object $entry) use ($metadata, $constructorPlan, &$creation, $completed, $configure, $resolveParameters): void {
                $attempt = $this->freshAttempt($creation);
                $context = $attempt->context;
                $succeeded = false;
                try {
                    $completed->assertUnchanged();
                    $constructorProof = null;
                    if ($context->constructorEnabled) {
                        ConstructorInvocation::initialize(
                            $this->parameters,
                            $entry,
                            $metadata->constructor,
                            $constructorPlan,
                            $this->resolutionParameters->get($context),
                            $completed->assertUnchanged(...),
                            $constructorProof,
                        );
                    }

                    $attemptCompleted = $constructorProof === null ? $completed : $completed->with($constructorProof);
                    $attemptCompleted->assertUnchanged();
                    $attempt->initialize($entry);
                    $after = $this->attributes->processTracked(
                        $metadata->class,
                        AttributePhase::AfterInstantiation,
                        $context,
                    );
                    $attemptCompleted = $attemptCompleted->with($after);
                    $attemptCompleted->assertUnchanged();
                    $configure?->__invoke($entry);
                    $attemptCompleted->assertUnchanged();
                    $constructorProof?->assertParameterSourcesUnchanged();
                    $this->resolutionParameters->assertSetupSourcesUnchanged($context);
                    $succeeded = true;
                } finally {
                    if (!$succeeded) {
                        // Destroying a suspended Fiber unwinds finally without entering catch.
                        $creation = $this->freshAttempt($creation, $resolveParameters);
                    }
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
        ObjectCreationAttempt $creation,
        CompletedAttributeGuard $completed,
        ?Closure $configure,
        ?Closure $resolveParameters,
    ): object {
        return $this->proxies->makeProxy(
            $metadata->class->getName(),
            function (object $_proxy) use ($metadata, $constructorPlan, &$creation, $completed, $configure, $resolveParameters): object {
                $succeeded = false;
                try {
                    $entry = $this->eager(
                        $metadata,
                        $constructorPlan,
                        $this->freshAttempt($creation),
                        $completed,
                        $configure,
                    );
                    $succeeded = true;
                    return $entry;
                } finally {
                    if (!$succeeded) {
                        $creation = $this->freshAttempt($creation, $resolveParameters);
                    }
                }
            },
        );
    }

    /** @param (Closure():array<string|int,mixed>)|null $resolveParameters */
    private function freshAttempt(
        ObjectCreationAttempt $creation,
        ?Closure $resolveParameters = null,
    ): ObjectCreationAttempt {
        if ($resolveParameters !== null) {
            $source = self::parameterSource($creation->context->class->getName(), $resolveParameters);
            $attempt = $creation->retry(
                static fn(): array => ResolutionMetadata::publicParameters($source()),
            );
            $this->resolutionParameters->attach($attempt->context, $source);
            return $attempt;
        }

        $attempt = $creation->retry();
        $this->resolutionParameters->copy($creation->context, $attempt->context);
        return $attempt;
    }

    /**
     * @param array<string|int,mixed>|Closure():array<string|int,mixed> $parameters
     * @return (Closure(ObjectCreationContext):void)|null
     */
    private function parameterReadGuard(ObjectMetadata $metadata, array|Closure $parameters): ?Closure
    {
        if (!$parameters instanceof Closure && MappedRequestContext::get($parameters) === null) {
            return null;
        }
        $targets = MappedRequestParameterSourceGuard::potentialSourceTargets($metadata->constructorTargets);
        if ($targets === []) {
            return null;
        }
        $store = $this->resolutionParameters;
        $owner = $metadata->class->getName();

        // Fresh attempts share this guard, but each reads its own private source.
        return static function (ObjectCreationContext $context) use ($store, $targets, $owner): void {
            MappedRequestParameterSourceGuard::assertTargetsContextNoConflicts(
                $targets,
                $store->get($context),
                $owner,
                autoload: false,
            );
        };
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

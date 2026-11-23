<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Entry;

use Closure;
use Componenta\DI\Attribute\Composition\Capability\ConstructorPolicy;
use Componenta\DI\CallableInvokerInterface;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Internal\AttributeCallbackInvoker;
use Componenta\DI\Internal\AttributeExecution;
use Componenta\DI\Internal\CompletedAttributeGuard;
use Componenta\DI\Internal\EntryLookupRevision;
use Componenta\DI\Internal\ObjectAttributeAccess;
use Componenta\DI\Internal\Resolver\Parameter\ParameterExecution;
use Componenta\DI\Internal\Resolver\Parameter\ParameterExecutionAccess;
use Componenta\DI\Internal\Resolver\Parameter\PreparedParameterPlan;
use Componenta\DI\Internal\Resolver\Parameter\Request\MappedRequestContext;
use Componenta\DI\Internal\Resolver\Parameter\Request\MappedRequestParameterSourceGuard;
use Componenta\DI\Object\CreationStrategy;
use Componenta\DI\ProxyFactoryInterface;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Attribute\AttributeProcessor;
use Componenta\DI\Resolver\Entry\EntryParameters;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Entry\SetUp\SetUpValueUnwrapperInterface;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use ReflectionClass;
use ReflectionMethod;

use function Componenta\DI\Internal\is_entry_class_eligible;

/** Runs the object creation lifecycle and keeps ownership of parameter sources. @internal */
final class ObjectCreation
{
    private readonly ObjectPlans $objectPlans;
    private readonly AttributeExecution $attributes;
    private readonly ParameterExecution $parameters;
    private readonly ObjectResolutionParameterStore $parametersStore;

    public function __construct(
        AttributeProcessor $attributes,
        ParametersResolver $parameters,
        private readonly ProxyFactoryInterface $proxies,
    ) {
        $this->attributes = ObjectAttributeAccess::executor($attributes);
        $this->parameters = ParameterExecutionAccess::for($parameters);
        if (!$this->parameters->usesAttributePlans($this->attributes->plans)) {
            throw new InvalidConfigurationException('Object and parameter processing must use the same attribute plans.');
        }
        $this->parametersStore = new ObjectResolutionParameterStore();
        $this->objectPlans = new ObjectPlans($this->parameters, $this->attributes);
    }

    public function setupHandler(
        CallableInvokerInterface $invoker,
        AttributeCallbackInvoker $callbacks,
        SetUpValueUnwrapperInterface ...$unwrappers,
    ): AttributeHandlerInterface {
        return new SetUpRunner($invoker, $this->parametersStore, $callbacks, ...$unwrappers);
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
     * @param array<string|int,mixed>|EntryParameters $params
     * @param Closure(object):void|null $configure Runs after constructor and attribute initialization.
     * @param (Closure(): (array<string|int,mixed>|EntryParameters))|null $resolveParameters Materializes declared parameter sources on demand.
     */
    public function create(
        string|ReflectionClass $class,
        array|EntryParameters $params = [],
        ?Closure $configure = null,
        ?Closure $resolveParameters = null,
    ): object {
        $entry = $this->createWithArguments($class, $params, $configure, $resolveParameters, $completion);
        // The inner frame has released the deferred arguments it owned.
        $completion?->assertLoadedAfterArgumentRetirement();
        return $entry;
    }

    /**
     * @template T of object
     * @param class-string<T>|ReflectionClass<T> $class
     * @param array<string|int,mixed>|EntryParameters $params
     * @param Closure(object):void|null $configure
     * @param (Closure(): (array<string|int,mixed>|EntryParameters))|null $resolveParameters
     * @param-out ?CompletedAttributeGuard $completion
     */
    private function createWithArguments(
        string|ReflectionClass $class,
        array|EntryParameters $params,
        ?Closure $configure,
        ?Closure $resolveParameters,
        ?CompletedAttributeGuard &$completion,
    ): object {
        if ($resolveParameters !== null && $params !== []) {
            throw new InvalidConfigurationException('Supply one source of construction parameters.');
        }
        // Metadata discovery may run autoloaders. Capture supplied slots first.
        $input = $params === [] ? null : ConstructionParameters::from($params);
        $metadata = $this->objectPlans->metadata($class);
        $className = $metadata->class->getName();
        $input ??= ConstructionParameters::from([]);
        if (!$metadata->hasAttributeHandlers) {
            $target = $metadata->attributeTarget;
            $completed = $this->attributes->skippedBeforePhase($target);
            $this->attributes->recordBootstrapUse($target, AttributePhase::BeforeInstantiation);
            $parameters = $resolveParameters === null ? $input : ConstructionParameters::from($resolveParameters());
            $provenance = ConstructionParameters::provenance($parameters);
            $entry = ConstructorInvocation::create(
                $this->parameters,
                $metadata->class,
                $metadata->constructor,
                $this->objectPlans->constructorPlan($metadata),
                ConstructionParameters::invocationArguments($parameters),
                $completed,
                $constructorProof,
                $provenance,
            );
            if ($constructorProof !== null) {
                $completed = $completed->with($constructorProof);
            }
            $completed->assertUnchanged();
            $target = $metadata->attributeTarget;
            if ($this->attributes->hasHandlers($target)) {
                $attempt = ObjectCreationAccess::begin(
                    $metadata->class,
                    ConstructionParameters::invocationArguments($parameters),
                    $this->parameterReadGuard($metadata, $provenance !== null),
                );
                $creation = $attempt->context;
                $this->parametersStore->attach($creation, $parameters);
                $attempt->initialize($entry);
                $after = $this->attributes->processTracked($target, AttributePhase::AfterInstantiation, $attempt);
            } else {
                $this->attributes->recordBootstrapUse($target, AttributePhase::AfterInstantiation);
                $after = $this->attributes->skippedAfterPhase($target);
            }
            $completed = $completed->with($after);
            $completed->assertUnchanged();
            $configure?->__invoke($entry);
            $completed->assertUnchanged();
            $constructorProof?->assertParameterSourcesUnchanged();
            if (isset($creation)) {
                $this->parametersStore->assertSetupSourcesUnchanged($creation);
            }
            $setupChecks = isset($creation) ? $this->parametersStore->setupSourceChecks($creation) : [];
            self::recordMappedPublication($entry, $completed, $metadata->constructor, $provenance, $setupChecks);
            $completion = $resolveParameters === null ? null : $completed->withRetirementSourceChecks(
                $metadata->constructor,
                $provenance,
                $setupChecks,
            );
            return $entry;
        }

        MappedRequestParameterSourceGuard::assertClassNoConflicts($className, ConstructionParameters::provenance($input));

        $parameterSource = $resolveParameters === null
            ? $input
            : self::parameterSource($className, $resolveParameters);

        $attempt = ObjectCreationAccess::begin(
            $metadata->class,
            $parameterSource instanceof Closure
                ? static fn(): array => ConstructionParameters::invocationArguments($parameterSource())
                : ConstructionParameters::invocationArguments($parameterSource),
            $this->parameterReadGuard($metadata, ConstructionParameters::provenance($input) !== null || $resolveParameters !== null),
        );
        $creation = $attempt->context;
        $this->parametersStore->attach($creation, $parameterSource);

        $completed = $this->attributes->processTracked($metadata->attributeTarget, AttributePhase::BeforeInstantiation, $creation);

        $constructorPlan = $creation->constructorEnabled
            ? $this->objectPlans->constructorPlan($metadata)
            : $this->parameters->prepareTargets([]);

        $completion = null;
        $recordCompletion = $resolveParameters === null ? null : static function (CompletedAttributeGuard $proof) use (&$completion): void {
            $completion = $proof;
        };
        return match ($creation->strategy) {
            CreationStrategy::Eager => $this->eager($metadata, $constructorPlan, $attempt, $completed, $configure, $recordCompletion),
            CreationStrategy::Lazy => $this->lazy($metadata, $constructorPlan, $attempt, $completed, $configure, $resolveParameters),
            CreationStrategy::Proxy => $this->proxy($metadata, $constructorPlan, $attempt, $completed, $configure, $resolveParameters),
        };
    }

    /**
     * @param Closure(object):void|null $configure
     * @param (Closure(CompletedAttributeGuard):void)|null $recordCompletion
     */
    private function eager(
        ObjectMetadata $metadata,
        PreparedParameterPlan $constructorPlan,
        ObjectCreationAttempt $attempt,
        CompletedAttributeGuard $completed,
        ?Closure $configure,
        ?Closure $recordCompletion = null,
        ?object $publishedAs = null,
    ): object {
        $creation = $attempt->context;
        $completed->assertUnchanged();
        $constructorProof = null;
        if ($creation->constructorEnabled) {
            $parameters = $this->parametersStore->get($creation);
            $entry = ConstructorInvocation::create(
                $this->parameters,
                $metadata->class,
                $metadata->constructor,
                $constructorPlan,
                ConstructionParameters::invocationArguments($parameters),
                $completed,
                $constructorProof,
                ConstructionParameters::provenance($parameters),
            );
        } else {
            $entry = $metadata->class->newInstanceWithoutConstructor();
        }

        if ($constructorProof !== null) {
            $completed = $completed->with($constructorProof);
        }
        $completed->assertUnchanged();
        $attempt->initialize($entry);
        $after = $this->attributes->processTracked($metadata->attributeTarget, AttributePhase::AfterInstantiation, $attempt);
        $completed = $completed->with($after);
        $completed->assertUnchanged();
        $configure?->__invoke($entry);
        $completed->assertUnchanged();
        $constructorProof?->assertParameterSourcesUnchanged();
        $this->parametersStore->assertSetupSourcesUnchanged($creation);

        $provenance = $this->parametersStore->resolvedProvenance($creation);
        $setupChecks = $this->parametersStore->setupSourceChecks($creation);
        self::recordMappedPublication($publishedAs ?? $entry, $completed, $metadata->constructor, $provenance, $setupChecks);
        $recordCompletion?->__invoke($completed->withRetirementSourceChecks(
            $metadata->constructor,
            $provenance,
            $setupChecks,
        ));
        return $entry;
    }

    /**
     * @param Closure(object):void|null $configure
     * @param (Closure(): (array<string|int,mixed>|EntryParameters))|null $resolveParameters
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
                $lookupRevision = EntryLookupRevision::current();
                $attempt = $this->freshAttempt($creation);
                $context = $attempt->context;
                $succeeded = false;
                try {
                    $completed->assertUnchanged();
                    $constructorProof = null;
                    if ($context->constructorEnabled) {
                        $parameters = $this->parametersStore->get($context);
                        ConstructorInvocation::initialize(
                            $this->parameters,
                            $entry,
                            $metadata->constructor,
                            $constructorPlan,
                            ConstructionParameters::invocationArguments($parameters),
                            $completed,
                            $constructorProof,
                            ConstructionParameters::provenance($parameters),
                        );
                    }

                    $attemptCompleted = $constructorProof === null ? $completed : $completed->with($constructorProof);
                    $attemptCompleted->assertUnchanged();
                    $attempt->initialize($entry);
                    $after = $this->attributes->processTracked($metadata->attributeTarget, AttributePhase::AfterInstantiation, $attempt);
                    $attemptCompleted = $attemptCompleted->with($after);
                    $attemptCompleted->assertUnchanged();
                    $configure?->__invoke($entry);
                    $attemptCompleted->assertUnchanged();
                    $constructorProof?->assertParameterSourcesUnchanged();
                    $this->parametersStore->assertSetupSourcesUnchanged($context);
                    EntryLookupRevision::assertCompleteSince($lookupRevision, $metadata->class->getName());
                    self::recordMappedPublication(
                        $entry,
                        $attemptCompleted,
                        $metadata->constructor,
                        $this->parametersStore->resolvedProvenance($context),
                        $this->parametersStore->setupSourceChecks($context),
                    );
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
     * @param (Closure(): (array<string|int,mixed>|EntryParameters))|null $resolveParameters
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
            function (object $proxy) use ($metadata, $constructorPlan, &$creation, $completed, $configure, $resolveParameters): object {
                $lookupRevision = EntryLookupRevision::current();
                $succeeded = false;
                try {
                    $entry = $this->eager(
                        $metadata,
                        $constructorPlan,
                        $this->freshAttempt($creation),
                        $completed,
                        $configure,
                        publishedAs: $proxy,
                    );
                    EntryLookupRevision::assertCompleteSince($lookupRevision, $metadata->class->getName());
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

    /** @param list<Closure():void> $setupChecks */
    private static function recordMappedPublication(
        object $entry,
        CompletedAttributeGuard $completed,
        ?ReflectionMethod $constructor,
        ?MappedRequestContext $provenance,
        array $setupChecks,
    ): void {
        if ($provenance?->tracksPublication() === true) {
            $provenance->recordPublication($entry, $completed->sourceChecksForRelease($constructor, $provenance, $setupChecks));
        }
    }

    /** @param (Closure(): (array<string|int,mixed>|EntryParameters))|null $resolveParameters */
    private function freshAttempt(
        ObjectCreationAttempt $creation,
        ?Closure $resolveParameters = null,
    ): ObjectCreationAttempt {
        if ($resolveParameters !== null) {
            $source = self::parameterSource($creation->context->class->getName(), $resolveParameters);
            $attempt = $creation->retry(
                static fn(): array => ConstructionParameters::invocationArguments($source()),
            );
            $this->parametersStore->attach($attempt->context, $source);
            return $attempt;
        }

        $attempt = $creation->retry();
        $this->parametersStore->copy($creation->context, $attempt->context);
        return $attempt;
    }

    /** @return (Closure(ObjectCreationContext):void)|null */
    private function parameterReadGuard(ObjectMetadata $metadata, bool $mayHaveProvenance): ?Closure
    {
        if (!$mayHaveProvenance) {
            return null;
        }
        $targets = MappedRequestParameterSourceGuard::potentialSourceTargets($metadata->constructorTargets);
        if ($targets === []) {
            return null;
        }
        $owner = $metadata->class->getName();
        $store = $this->parametersStore;

        return static function (ObjectCreationContext $context) use ($store, $targets, $owner): void {
            MappedRequestParameterSourceGuard::assertTargetsProvenanceNoConflicts(
                $targets,
                ConstructionParameters::provenance($store->get($context)),
                $owner,
                autoload: false,
            );
        };
    }

    /**
     * @param class-string $class
     * @param Closure(): (array<string|int,mixed>|EntryParameters) $resolveParameters
     * @return Closure():EntryParameters
     */
    private static function parameterSource(string $class, Closure $resolveParameters): Closure
    {
        return static function () use ($resolveParameters, $class): EntryParameters {
            /** @var EntryParameters|null $resolved */
            static $resolved = null;
            /** @var bool $resolving */
            static $resolving = false;
            if ($resolved !== null) {
                return $resolved;
            }
            if ($resolving) {
                throw new \LogicException('The parameters for this object attempt are already being resolved.');
            }

            $resolving = true;
            try {
                $parameters = ConstructionParameters::from($resolveParameters());
                MappedRequestParameterSourceGuard::assertClassNoConflicts($class, ConstructionParameters::provenance($parameters));
                return $resolved = $parameters;
            } finally {
                $resolving = false;
            }
        };
    }
}

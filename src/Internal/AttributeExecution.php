<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Closure;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Exception\ExceptionInterface;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Internal\Resolver\Entry\ObjectCreationAccess;
use Componenta\DI\Internal\Resolver\Entry\ObjectCreationAttempt;
use Componenta\DI\Internal\Resolver\Entry\PropertyValueHandlerAccess;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Attribute\Handler\CastHandler;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use ReflectionClass;
use ReflectionMethod;
use ReflectionObject;
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
final class AttributeExecution
{
    /**
     * @var array<class-string,array{
     *     revision:int,
     *     hasHandlers:bool,
     *     requiresFreshValidation:bool,
     *     before:list<ReflectionClass<covariant object>|ReflectionProperty|ReflectionMethod>,
     *     after:list<ReflectionClass<covariant object>|ReflectionProperty|ReflectionMethod>
     * }>
     */
    private array $cache = [];

    public function __construct(
        public readonly AttributePlans $plans,
        private readonly ?BootstrapResolutionGuard $bootstrap,
    ) {}

    /**
     * @internal
     * @param ReflectionClass<covariant object> $class
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
     * @param ReflectionClass<covariant object> $class
     */
    public function skippedBeforePhase(ReflectionClass $class): CompletedAttributeGuard
    {
        return $this->skippedPhase($class, AttributePhase::BeforeInstantiation);
    }

    /**
     * Records validated metadata in the handlerless after phase.
     * @internal
     * @param ReflectionClass<covariant object> $class
     */
    public function skippedAfterPhase(ReflectionClass $class): CompletedAttributeGuard
    {
        return $this->skippedPhase($class, AttributePhase::AfterInstantiation);
    }

    /** @param ReflectionClass<covariant object> $class */
    private function skippedPhase(ReflectionClass $class, AttributePhase $phase): CompletedAttributeGuard
    {
        $guard = new CompletedAttributeGuard($this->plans);
        $targets = $this->executionPlan($class)[$phase === AttributePhase::BeforeInstantiation ? 'before' : 'after'];
        foreach ($targets as $target) {
            $usages = $phase === AttributePhase::AfterInstantiation
                ? array_values(array_filter(
                    $this->plans->build($target)->usages,
                    static fn(AttributeUsage $usage): bool => !$usage->definition->handler instanceof AttributeHandlerInterface,
                ))
                : [];
            // Discovery may reveal an object handler after the fast-path choice.
            // Only metadata was validated here; no handler can count as completed.
            $guard->record($target, new AttributePlan($target, $usages), $phase);
        }
        return $guard;
    }

    /** @param ReflectionClass<covariant object> $class */
    public function prepare(ReflectionClass $class): void
    {
        $this->executionPlan($class);
    }

    /** @param ReflectionClass<covariant object> $class */
    public function hasHandlers(ReflectionClass $class): bool
    {
        $plan = $this->executionPlan($class);
        return $plan['hasHandlers'];
    }

    /** @param ReflectionClass<covariant object> $class */
    public function process(
        ReflectionClass $class,
        AttributePhase $phase,
        ObjectCreationContext $context,
    ): void {
        $this->processTracked(ReflectionSnapshot::class($class), $phase, $context)->assertUnchanged();
    }

    /**
     * @internal
     * @param ReflectionClass<covariant object> $class
     */
    public function processTracked(
        ReflectionClass $class,
        AttributePhase $phase,
        ObjectCreationContext|ObjectCreationAttempt $context,
    ): CompletedAttributeGuard {
        $attempt = null;
        if ($context instanceof ObjectCreationAttempt) {
            $attempt = $context;
            $context = $attempt->context;
        }
        if ($phase === AttributePhase::Both) {
            throw new InvalidConfigurationException(
                'Attribute execution requires a concrete runtime phase.',
            );
        }
        if ($class->getName() !== $context->class->getName()) {
            throw new InvalidConfigurationException('Attribute processing target must match the creation context class.');
        }
        $instanceReflection = null;
        if ($class::class === ReflectionObject::class) {
            $instanceReflection = $class;
            $class = ReflectionSnapshot::forConstruction($class);
        }

        return ObjectCreationAccess::dispatch(
            $context,
            $phase,
            fn(): CompletedAttributeGuard => $this->dispatchPhase($class, $phase, $context, $instanceReflection, $attempt),
        );
    }

    /**
     * @param ReflectionClass<covariant object> $class
     * @param ReflectionObject<covariant object>|null $instanceReflection
     */
    private function dispatchPhase(
        ReflectionClass $class,
        AttributePhase $phase,
        ObjectCreationContext $context,
        ?ReflectionObject $instanceReflection,
        ?ObjectCreationAttempt $attempt,
    ): CompletedAttributeGuard {
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
                $declaration = $target::class === ReflectionProperty::class ? null : $usage->target;
                ObjectCreationAccess::withinProperty(
                    $context,
                    $target,
                    function () use ($class, $target, $sequence, $context, $attempt): void {
                        $this->processTarget($class, $target, $sequence, $context, attempt: $attempt);
                    },
                    $declaration instanceof ReflectionProperty ? $declaration : null,
                );
                if ($sequence->completed > $completed) {
                    $completedProperties[$key] = true;
                }
            } else {
                $this->processTarget($class, $target, $sequence, $context, $instanceReflection);
            }
        }
        $sequence->recordBootstrapUse($this->bootstrap);
        return $sequence->completionGuard();
    }

    /**
     * @param ReflectionClass<covariant object> $class
     * @param ReflectionClass<covariant object>|ReflectionProperty|ReflectionMethod $target
     * @param ReflectionObject<covariant object>|null $instanceReflection
     */
    private function processTarget(
        ReflectionClass $class,
        ReflectionClass|ReflectionProperty|ReflectionMethod $target,
        ObjectAttributeSequence $sequence,
        ObjectCreationContext $context,
        ?ReflectionObject $instanceReflection = null,
        ?ObjectCreationAttempt $attempt = null,
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
                    $sequence->retainInstance($usage, $attribute);
                    continue;
                }

                $sequence->dispatch($usage);
                // Instance-specific member data is for handlers. The plan uses the captured class.
                if ($target instanceof ReflectionClass) {
                    $handlerTarget = $instanceReflection?->getName() === $class->getName() ? $instanceReflection : $context->class;
                } else {
                    $member = $target;
                    if (ReflectionSnapshot::hasBorrowedMetadata($target)) {
                        $declaration = $usage->target;
                        if (!ReflectionSnapshot::sameDeclaration($target, $declaration)) {
                            $member = $declaration;
                        }
                    }
                    $handlerTarget = ReflectionSnapshot::target($member, $class::class === ReflectionClass::class ? $class : $context->class);
                }
                if ($attempt !== null && $handlerTarget instanceof ReflectionProperty
                    && ($handler instanceof CastHandler || $handler instanceof TransformHandler)
                ) {
                    PropertyValueHandlerAccess::handle($handler, $attribute, $handlerTarget, $attempt);
                } else {
                    $handler->handle($attribute, $handlerTarget, $context);
                }
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
     * @internal
     * @param ReflectionClass<covariant object> $class
     * @return array{hasHandlers:bool,requiresFreshValidation:bool}
     */
    public function metadataState(ReflectionClass $class): array
    {
        $plan = $this->executionPlan($class);
        return ['hasHandlers' => $plan['hasHandlers'], 'requiresFreshValidation' => $plan['requiresFreshValidation']];
    }

    /**
     * @param ReflectionClass<covariant object> $class
     * @return array{
     *     revision:int,
     *     hasHandlers:bool,
     *     requiresFreshValidation:bool,
     *     before:list<ReflectionClass<covariant object>|ReflectionProperty|ReflectionMethod>,
     *     after:list<ReflectionClass<covariant object>|ReflectionProperty|ReflectionMethod>
     * }
     */
    private function executionPlan(ReflectionClass $class): array
    {
        $name = $class->getName();
        $cacheable = $class::class === ReflectionClass::class;
        do {
            $revision = $this->plans->refreshAvailability();
            $cached = $this->cache[$name] ?? null;
            if ($cacheable && $cached !== null && $cached['revision'] === $revision) {
                return $cached;
            }

            $class = ReflectionSnapshot::class($class);
            $properties = self::properties($class);
            $methods = self::methods($class);
            $hasAttributes = static fn(ReflectionClass|ReflectionProperty|ReflectionMethod $target): bool => $target->getAttributes() !== [];
            if ($cacheable) {
                $classTargets = $hasAttributes($class) ? [$class] : [];
                $properties = array_filter($properties, $hasAttributes);
                $methods = array_filter($methods, $hasAttributes);
                $before = [...$classTargets, ...$properties, ...$methods];
                $after = $classTargets === [] || $properties === [] ? $before : [...$properties, ...$classTargets, ...$methods];
            } else {
                $properties = self::snapshotNativeMembers($properties);
                $methods = self::snapshotNativeMembers($methods);
                $before = array_values(array_filter([$class, ...$properties, ...$methods], $hasAttributes));
                $after = array_values(array_filter([...$properties, $class, ...$methods], $hasAttributes));
            }
            $hasHandlers = false;
            $requiresFreshValidation = false;
            foreach ($before as $target) {
                $plan = $this->plans->build($target);
                $requiresFreshValidation = $requiresFreshValidation || $this->plans->requiresFreshValidation($plan);
                foreach ($plan->usages as $usage) {
                    if ($usage->definition->handler instanceof AttributeHandlerInterface) {
                        $hasHandlers = true;
                    }
                }
            }
            // A member's autoload may reveal handlers on an earlier target.
            // Return and cache only a plan discovered at one registry revision.
        } while ($revision !== $this->plans->refreshAvailability());

        $plan = [
            'revision' => $revision,
            'hasHandlers' => $hasHandlers,
            'requiresFreshValidation' => $requiresFreshValidation,
            'before' => $before,
            'after' => $after,
        ];
        if ($cacheable && !$requiresFreshValidation) {
            $this->cache[$name] = $plan;
        }
        return $plan;
    }

    /**
     * A derived class reflector may return members that it still owns.
     * @param list<ReflectionProperty|ReflectionMethod> $members
     * @return list<ReflectionProperty|ReflectionMethod>
     */
    private static function snapshotNativeMembers(array $members): array
    {
        foreach ($members as $index => $member) {
            if (!ReflectionSnapshot::hasBorrowedMetadata($member) && $member->getAttributes() !== []) {
                $snapshot = ReflectionSnapshot::target($member);
                if ($snapshot instanceof ReflectionProperty || $snapshot instanceof ReflectionMethod) {
                    $members[$index] = $snapshot;
                }
            }
        }
        return $members;
    }

    /**
     * @param ReflectionClass<covariant object> $class
     * @return list<ReflectionProperty>
     */
    private static function properties(ReflectionClass $class): array
    {
        /** @var list<ReflectionProperty> $properties */
        $properties = array_values($class->getProperties());
        foreach ($properties as $index => $property) {
            if ($property::class === ReflectionProperty::class && $property->isPrivateSet()) {
                // Reflection writes use the lookup class as scope. Inherited
                // private setters require their declaration scope, including hooks.
                $properties[$index] = $property->getDeclaringClass()->getProperty($property->getName());
            }
        }
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
     * @param ReflectionClass<covariant object> $class
     * @return list<ReflectionMethod>
     */
    private static function methods(ReflectionClass $class): array
    {
        if ($class instanceof ReflectionObject && $class->getName() === Closure::class) {
            // Object phases inspect class declarations. A closure supplies its own invocation metadata.
            $class = ReflectionSnapshot::forConstruction($class);
        }

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

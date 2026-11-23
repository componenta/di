<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Attribute;
use Closure;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\Composition\Capability\InvocationOnlyValueProvider;
use Componenta\DI\Attribute\Composition\Capability\ValueTransformer;
use Componenta\DI\Attribute\Composition\Rule\ProxyCompositionRule;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;
use Throwable;
use WeakMap;

/** Builds and validates semantic plans and owns their cache. @internal */
final class AttributePlans
{
    /** @var array<string, AttributePlan> */
    private array $namedPlans = [];
    /** @var array<class-string,int> */
    private array $attributeTargetFlags = [];
    private int $availabilityRevision = -1;
    private int $namedPlansRevision = -1;
    private readonly AttributeOrdering $ordering;
    /** @var WeakMap<AttributePlan,true>|null */
    private ?WeakMap $freshValidation = null;
    /** @var WeakMap<AttributePlan,array<int,ReflectionAttribute<object>>>|null */
    private ?WeakMap $borrowedDeclarations = null;
    public function __construct(private readonly AttributeDefinitions $registry)
    {
        $this->ordering = new AttributeOrdering();
    }

    public function requiresFreshValidation(AttributePlan $plan): bool
    {
        return isset($this->freshValidation[$plan]);
    }

    /**
     * @param ReflectionClass<covariant object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $target
     * @param array<int,ReflectionAttribute<object>>|null $captured
     * @param array<int,ReflectionAttribute<object>>|null $consumed
     */
    public function consumedSource(
        ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target,
        AttributePlan $plan,
        ?array $captured = null,
        ?array $consumed = null,
    ): AttributeMetadataSource {
        return new AttributeMetadataSource($target, $plan, $captured, $consumed ?? $this->borrowedDeclarations[$plan] ?? null);
    }

    /**
     * Rechecks availability against the declarations already selected for this execution.
     * @param ReflectionClass<covariant object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $target
     */
    public function revalidateSelectedDeclarations(
        ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target,
        AttributePlan $plan,
    ): AttributePlan {
        $selected = $this->borrowedDeclarations[$plan] ?? null;
        return $selected === null
            ? $this->build($target)
            : $this->build($plan->target, $selected);
    }

    public function refreshAvailability(): int
    {
        return $this->registry->refreshAvailability();
    }

    public int $cacheRevision {
        get => $this->registry->cacheRevision;
    }

    /** @internal */
    public function refreshLoaded(): int
    {
        return $this->registry->refreshLoaded();
    }

    /** @internal Preserves discovery for an uncached native declaration with no attributes. */
    public function refreshEmptyUncachedTarget(): void
    {
        do {
            $revision = $this->synchronizeNamedPlans();
        } while ($revision !== $this->registry->refreshAvailability());
    }

    /** @internal */
    public bool $hasCustomRules {
        get => $this->registry->hasCustomRules;
    }

    /**
     * @internal
     * @param class-string $attribute
     */
    public function loadedDefinition(string $attribute): ?AttributeDefinition
    {
        if (!class_exists($attribute, false) && !interface_exists($attribute, false)) {
            return null;
        }
        return $this->registry->definition($attribute);
    }

    /**
     * Compares declared semantics without polling availability or running composition rules.
     * @param ReflectionClass<covariant object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $target
     * @param array<int,ReflectionAttribute<object>>|null $observed
     * @param (Closure(AttributeUsage):bool)|null $included
     */
    public function hasSameLoadedDeclarations(
        ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target,
        AttributePlan $plan,
        ?array $observed = null,
        ?Closure $included = null,
    ): bool {
        $consumed = [];
        foreach ($plan->usages as $usage) {
            if ($included === null || $included($usage)) {
                $consumed[$usage->declarationOrder] = [$usage->definition, $usage->attributeClass];
            }
        }
        foreach ($this->discoverUsages($target, $observed ?? $target->getAttributes(), autoload: false) as $usage) {
            if ($included !== null && !$included($usage)) {
                continue;
            }
            $index = $usage->declarationOrder;
            if (($consumed[$index] ?? null) !== [$usage->definition, $usage->attributeClass]) {
                return false;
            }
            unset($consumed[$index]);
        }
        return $consumed === [];
    }

    /**
     * @param Closure|ReflectionClass<covariant object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $target
     * @param array<int,ReflectionAttribute<object>>|null $observed
     */
    public function build(
        Closure|ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target,
        ?array $observed = null,
        ?AttributePlan $previousPlan = null,
    ): AttributePlan {
        $closure = null;
        if ($target instanceof Closure) {
            $closure = $target;
            $target = ReflectionSnapshot::target($closure);
        }
        $key = $observed === null ? self::cacheKey($target) : null;
        $metadata = null;
        $previous = null;
        if ($key === null && ReflectionSnapshot::hasBorrowedMetadata($target)) {
            $metadata = new AttributeMetadataSource($target, $previousPlan);
            $target = $metadata->target;
            if ($previousPlan !== null) {
                $previous = $this->borrowedDeclarations[$previousPlan] ?? null;
            }
        }
        while (true) {
            $revision = $this->synchronizeNamedPlans();

            if ($key !== null && isset($this->namedPlans[$key])) {
                return $this->namedPlans[$key];
            }

            $awaitsDiscovery = false;
            $validatedUserRules = false;
            $attributes = $observed ?? $metadata?->attributeOverride($previous) ?? $target->getAttributes();
            if ($metadata !== null) {
                $previous = $attributes;
            }
            $usages = $attributes === [] ? [] : $this->discoverUsages(
                $target,
                $attributes,
                awaitsDiscovery: $awaitsDiscovery,
                closure: $closure,
            );
            unset($attributes);
            // Loading a later attribute may reveal one skipped earlier. Validate
            // a complete revision, before a missing dependency can fail creation.
            if ($revision !== $this->registry->refreshAvailability()) {
                continue;
            }

            if ($usages === []) {
                break;
            }

            $this->assertCapabilityCardinality($target, $usages);
            $this->assertInvocationOnlyComposition($target, $usages);
            $this->assertParameterHandlerComposition($target, $usages);
            $this->assertDependencies($target, $usages);
            $validatedUserRules = $this->assertCustomRules($usages);

            // Rules may evaluate native arguments that load another attribute.
            // Revalidate the complete metadata before publishing a direct plan;
            // no runtime handlers have been dispatched during this preparation.
            if ($validatedUserRules && $revision !== $this->registry->refreshAvailability()) {
                continue;
            }
            break;
        }

        $plan = new AttributePlan($closure ?? $target, $this->ordering->order($target, $usages));
        if ($previous !== null) {
            $declarations = $this->borrowedDeclarations ??= new WeakMap();
            $declarations[$plan] = $previous;
        }
        if ($awaitsDiscovery || $validatedUserRules) {
            $fresh = $this->freshValidation ??= new WeakMap();
            $fresh[$plan] = true;
            return $plan;
        }
        if ($key === null) {
            // User rules can read mutable state. ReflectionObject and closure
            // targets must not be retained by a named shared plan.
            return $plan;
        }

        return $this->namedPlans[$key] = $plan;
    }

    /**
     * @param ReflectionClass<covariant object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $target
     * @param array<int,ReflectionAttribute<object>> $attributes
     * @return list<AttributeUsage>
     */
    private function discoverUsages(
        ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target,
        array $attributes,
        bool $autoload = true,
        bool &$awaitsDiscovery = false,
        ?Closure $closure = null,
    ): array {
        $usages = [];
        foreach ($attributes as $index => $reflectionAttribute) {
            ReflectionSnapshot::assertNativeAttribute($reflectionAttribute);
            /** @var class-string $attributeClass */
            $attributeClass = $reflectionAttribute->getName();
            $definition = $autoload ? $this->registry->definition($attributeClass) : $this->loadedDefinition($attributeClass);
            if ($definition === null) {
                if ($autoload && $this->registry->awaitsDiscovery($attributeClass)) {
                    $awaitsDiscovery = true;
                }
                continue;
            }

            if (!$this->supportsTarget($attributeClass, $target)) {
                if (self::isPromotedDuplicateTarget($target)
                    && ($this->attributeTargetFlags($attributeClass) & (Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)) !== 0
                ) {
                    continue;
                }

                throw new AttributeCompositionException(sprintf(
                    'Attribute "%s" cannot target %s.',
                    $attributeClass,
                    self::targetKind($target),
                ));
            }

            if ($reflectionAttribute->isRepeated()
                && ($this->attributeTargetFlags($attributeClass) & Attribute::IS_REPEATABLE) === 0
            ) {
                throw new AttributeCompositionException(sprintf(
                    'Attribute "%s" must not be repeated on %s.',
                    $attributeClass,
                    describe_attribute_target($target),
                ));
            }

            $usages[] = new AttributeUsage(
                $reflectionAttribute,
                $definition,
                $closure ?? $target,
                $index,
            );
        }

        return $usages;
    }

    private function synchronizeNamedPlans(): int
    {
        $revision = $this->registry->refreshAvailability();
        if ($this->availabilityRevision === $revision) {
            return $revision;
        }

        $cacheRevision = $this->registry->cacheRevision;
        if ($this->namedPlansRevision !== $cacheRevision) {
            $this->namedPlans = [];
            $this->namedPlansRevision = $cacheRevision;
        }
        $this->availabilityRevision = $revision;

        return $revision;
    }

    /**
     * @param class-string $attributeClass
     * @param ReflectionClass<covariant object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $target
     */
    private function supportsTarget(
        string $attributeClass,
        ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target,
    ): bool {
        return ($this->attributeTargetFlags($attributeClass) & self::targetFlag($target)) !== 0;
    }

    /** @param class-string $attributeClass */
    private function attributeTargetFlags(string $attributeClass): int
    {
        if (isset($this->attributeTargetFlags[$attributeClass])) {
            return $this->attributeTargetFlags[$attributeClass];
        }

        try {
            $reflection = new ReflectionClass($attributeClass);
            $marker = $reflection->getAttributes(Attribute::class)[0] ?? null;
            if ($marker === null) {
                throw new AttributeCompositionException(sprintf(
                    'Registered DI attribute "%s" is not declared with #[Attribute].',
                    $attributeClass,
                ));
            }

            $metadata = $this->instantiate($marker, sprintf('#[Attribute] metadata for "%s"', $attributeClass));
            if (!$metadata instanceof Attribute) {
                throw new AttributeCompositionException(sprintf(
                    'Registered DI attribute "%s" has invalid #[Attribute] metadata.',
                    $attributeClass,
                ));
            }

            return $this->attributeTargetFlags[$attributeClass] = $metadata->flags;
        } catch (AttributeCompositionException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new AttributeCompositionException(
                sprintf('Cannot inspect registered DI attribute "%s": %s', $attributeClass, $e->getMessage()),
                previous: $e,
            );
        }
    }

    /**
     * @template T of object
     * @param ReflectionAttribute<T> $attribute
     * @return T
     */
    private function instantiate(ReflectionAttribute $attribute, string $context): object
    {
        try {
            return $attribute->newInstance();
        } catch (AttributeCompositionException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new AttributeCompositionException(
                sprintf('Cannot instantiate %s: %s', $context, $e->getMessage()),
                previous: $e,
            );
        }
    }

    /** @param ReflectionClass<covariant object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $target */
    private static function targetFlag(
        ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target,
    ): int {
        return match (true) {
            $target instanceof ReflectionClass => Attribute::TARGET_CLASS,
            $target instanceof ReflectionProperty => Attribute::TARGET_PROPERTY,
            $target instanceof ReflectionMethod => Attribute::TARGET_METHOD,
            default => Attribute::TARGET_PARAMETER,
        };
    }

    /** @param ReflectionClass<covariant object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $target */
    private static function isPromotedDuplicateTarget(
        ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target,
    ): bool {
        return ($target instanceof ReflectionProperty || $target instanceof ReflectionParameter)
            && $target->isPromoted();
    }

    /** @param ReflectionClass<covariant object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $target */
    private static function targetKind(
        ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target,
    ): string {
        return match (true) {
            $target instanceof ReflectionClass => 'class',
            $target instanceof ReflectionProperty => 'property',
            $target instanceof ReflectionMethod => 'method',
            default => 'parameter',
        };
    }

    /**
     * @param ReflectionClass<covariant object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $target
     * @param list<AttributeUsage> $usages
     */
    private function assertCapabilityCardinality(
        ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target,
        array $usages,
    ): void {
        foreach ($this->registry->policies() as $policy) {
            if ($policy->maxPerTarget === null) {
                continue;
            }

            $members = [];
            foreach ($usages as $usage) {
                if ($usage->hasCapability($policy->capability)) {
                    $members[] = $usage;
                }
            }

            if (count($members) <= $policy->maxPerTarget) {
                continue;
            }

            throw new AttributeCompositionException(sprintf(
                '%s accepts at most %d attribute(s) with capability %s; found %s.',
                describe_attribute_target($target),
                $policy->maxPerTarget,
                $policy->capability,
                implode(', ', array_map(
                    static fn(AttributeUsage $usage): string => '#[' . $usage->attributeClass . ']',
                    $members,
                )),
            ));
        }
    }

    /**
     * Invocation-only values belong to the current callable execution and must
     * not be captured by constructor-created object state.
     *
     * @param ReflectionClass<covariant object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $target
     * @param list<AttributeUsage> $usages
     */
    private function assertInvocationOnlyComposition(
        ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target,
        array $usages,
    ): void {
        $invocationOnly = null;
        foreach ($usages as $usage) {
            if ($usage->hasCapability(InvocationOnlyValueProvider::class)) {
                $invocationOnly = $usage;
                break;
            }
        }

        if ($invocationOnly === null) {
            return;
        }

        if (!$target instanceof ReflectionParameter) {
            throw new AttributeCompositionException(sprintf(
                '#[%s] is invocation-only and can target callable parameters only; got %s.',
                $invocationOnly->attributeClass,
                describe_attribute_target($target),
            ));
        }

        $function = $target->getDeclaringFunction();
        if ($function instanceof ReflectionMethod && $function->isConstructor()) {
            throw new AttributeCompositionException(sprintf(
                '#[%s] is invocation-only and cannot target constructor parameter $%s of %s::__construct().',
                $invocationOnly->attributeClass,
                $target->getName(),
                $function->getDeclaringClass()->getName(),
            ));
        }
    }

    /**
     * A parameter may have one source-handler and any number of transformer
     * handlers. Multiple definitions may intentionally share one source-handler
     * (for example #[Make] + #[Proxy]).
     *
     * @param ReflectionClass<covariant object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $target
     * @param list<AttributeUsage> $usages
     */
    private function assertParameterHandlerComposition(
        ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target,
        array $usages,
    ): void {
        if (!$target instanceof ReflectionParameter) {
            return;
        }

        /** @var array<int,ParameterAttributeHandlerInterface> $sources */
        $sources = [];
        foreach ($usages as $usage) {
            $handler = $usage->definition->handler;
            if (!$handler instanceof ParameterAttributeHandlerInterface) {
                continue;
            }

            if ($usage->hasCapability(ValueTransformer::class)) {
                continue;
            }

            $sources[spl_object_id($handler)] = $handler;
        }

        if (count($sources) <= 1) {
            return;
        }

        throw new AttributeCompositionException(sprintf(
            '%s resolves through multiple parameter source handlers: %s.',
            describe_attribute_target($target),
            implode(', ', array_map(
                static fn(ParameterAttributeHandlerInterface $handler): string => $handler::class,
                array_values($sources),
            )),
        ));
    }

    /**
     * @param ReflectionClass<covariant object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $target
     * @param list<AttributeUsage> $usages
     */
    private function assertDependencies(
        ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target,
        array $usages,
    ): void {
        foreach ($usages as $usage) {
            foreach ($usage->definition->requires as $selector) {
                if ($this->matching($selector, $usages, $usage) !== []) {
                    continue;
                }
                throw new AttributeCompositionException(sprintf(
                    '%s requires %s because of #[%s].',
                    describe_attribute_target($target),
                    $selector,
                    $usage->attributeClass,
                ));
            }

            foreach ($usage->definition->forbids as $selector) {
                if ($this->matching($selector, $usages, $usage) === []) {
                    continue;
                }
                throw new AttributeCompositionException(sprintf(
                    '%s forbids %s together with #[%s].',
                    describe_attribute_target($target),
                    $selector,
                    $usage->attributeClass,
                ));
            }
        }
    }

    /**
     * @param list<AttributeUsage> $usages
     * @return bool Whether a user rule ran and may have changed attribute availability.
     */
    private function assertCustomRules(array $usages): bool
    {
        if ($usages === []) {
            return false;
        }

        $validatedUserRules = false;
        $set = new AttributeSet($usages);
        foreach ($usages as $usage) {
            foreach ($usage->definition->rules as $rule) {
                try {
                    $rule->validate($usage, $set);
                    if (!$rule instanceof ProxyCompositionRule) {
                        $validatedUserRules = true;
                    }
                } catch (AttributeCompositionException $e) {
                    throw $e;
                } catch (Throwable $e) {
                    throw new AttributeCompositionException(
                        sprintf(
                            'Attribute composition rule "%s" failed for #[%s]: %s',
                            $rule::class,
                            $usage->attributeClass,
                            $e->getMessage(),
                        ),
                        previous: $e,
                    );
                }
            }
        }
        return $validatedUserRules;
    }

    /**
     * @param class-string $selector
     * @param list<AttributeUsage> $usages
     * @return list<AttributeUsage>
     */
    private function matching(string $selector, array $usages, ?AttributeUsage $except = null): array
    {
        $matches = [];
        foreach ($usages as $usage) {
            if ($usage !== $except && $usage->matches($selector)) {
                $matches[] = $usage;
            }
        }
        return $matches;
    }

    /** @param ReflectionClass<covariant object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $target */
    private static function cacheKey(
        ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target,
    ): ?string {
        // Derived reflectors can change metadata and carry application state.
        // Only exact native declarations are stable enough for named shared plans.
        return match ($target::class) {
            ReflectionClass::class => 'class:' . $target->getName(),
            ReflectionProperty::class => !$target->isDefault() ? null : sprintf(
                'property:%s::$%s',
                $target->getDeclaringClass()->getName(),
                $target->getName(),
            ),
            ReflectionMethod::class => is_closure_reflector($target) ? null : sprintf(
                'method:%s::%s()',
                $target->getDeclaringClass()->getName(),
                $target->getName(),
            ),
            ReflectionParameter::class => self::parameterCacheKey($target),
            default => null,
        };
    }

    private static function parameterCacheKey(ReflectionParameter $parameter): ?string
    {
        $function = $parameter->getDeclaringFunction();
        if (is_closure_reflector($function)) {
            return null;
        }

        $owner = $parameter->getDeclaringClass()?->getName();
        return sprintf(
            'parameter:%s%s:%d',
            $owner === null ? '' : $owner . '::',
            $function->getName(),
            $parameter->getPosition(),
        );
    }
}

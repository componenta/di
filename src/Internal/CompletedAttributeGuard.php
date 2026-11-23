<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Closure;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Attribute\Handler\MakeHandler;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;

/**
 * Retains completed metadata only for the current resolution.
 *
 * @internal
 * @phpstan-import-type ReflectionTarget from AttributeMetadataSource
 * @phpstan-type CompletedPhase array{source:AttributeMetadataSource,plan:AttributePlan,phase:?AttributePhase,observed:array<int,ReflectionAttribute<object>>}
 */
final class CompletedAttributeGuard
{
    /** @var list<CompletedPhase> */
    private array $completed = [];
    /** @var list<Closure():bool> */
    private array $parameterClassifications = [];
    /** @var (Closure():void)|null */
    private ?Closure $checkParameterSources = null;

    public function __construct(
        private readonly AttributePlans $plans,
        private int $verifiedRevision = -1,
    ) {}

    /**
     * @param ReflectionTarget $target
     * @param list<ReflectionAttribute<object>>|null $captured
     * @param array<int,ReflectionAttribute<object>>|null $consumed
     */
    public function record(
        ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target,
        AttributePlan $plan,
        ?AttributePhase $phase = null,
        ?array $captured = null,
        ?array $consumed = null,
    ): void {
        $source = $this->plans->consumedSource($target, $plan, $captured, $consumed);
        $attributes = $consumed ?? $source->attributes();
        // A derived reflector can omit a declaration after the plan has been consumed.
        if ($attributes === [] && $plan->usages === []) {
            return;
        }
        $this->completed[] = [
            'source' => $source,
            'plan' => $plan,
            'phase' => $phase,
            'observed' => $attributes,
        ];
    }

    /** @param Closure():bool $check Returns true if a newly available name was checked. */
    public function recordParameterClassification(Closure $check): void
    {
        $this->parameterClassifications[] = $check;
    }

    /** Combines completed phases for one attempt without changing a reusable earlier guard. */
    public function with(self $other): self
    {
        if ($this->plans !== $other->plans) {
            throw new \LogicException('Completed attribute guards must belong to the same plan builder.');
        }
        if ($other->completed === [] && $other->parameterClassifications === []) {
            return $this;
        }
        if ($this->completed === [] && $this->parameterClassifications === []) {
            return $other;
        }

        $combined = clone $this;
        $combined->completed = [...$this->completed, ...$other->completed];
        $combined->parameterClassifications = [...$this->parameterClassifications, ...$other->parameterClassifications];
        // Combining proofs of the same generation does not invalidate them.
        // The next assertion still polls and validates both if it changes.
        $combined->verifiedRevision = $this->verifiedRevision === $other->verifiedRevision
            ? $this->verifiedRevision
            : -1;
        return $combined;
    }

    public function assertUnchanged(): void
    {
        do {
            while (($revision = $this->plans->refreshAvailability()) !== $this->verifiedRevision) {
                foreach ($this->completed as $completed) {
                    $source = $completed['source'];
                    $target = $source->target;
                    $current = $this->plans->build($target, $source->attributeOverride($completed['observed']));
                    if (self::semantics($completed['plan'], $completed['phase']) !== self::semantics($current, $completed['phase'])) {
                        self::throwChanged($target, $completed['phase']);
                    }
                }
                $this->verifiedRevision = $revision;
            }
        } while ($this->parameterClassifications !== [] && $this->recheckParameterClassifications());
    }

    /** Checks loaded declarations, and classifications that may have changed during a completed before phase. */
    public function assertLoadedParametersUnchanged(?self $completedBefore = null): void
    {
        do {
            $this->assertLoadedParameterDeclarationsUnchanged();
            if ($completedBefore === null) {
                return;
            }

            $reclassified = $this->parameterClassifications !== [] && $this->recheckParameterClassifications();
            // A metadata read or watched classifier can load a constructor policy.
            $newDeclarations = $completedBefore->plans->refreshLoaded() !== $completedBefore->verifiedRevision;
            if ($newDeclarations) {
                $completedBefore->assertUnchanged();
            }
            $checked = $reclassified || $newDeclarations;
            if ($checked) {
                $this->checkParameterSources?->__invoke();
            }
        } while ($checked);
    }

    private function assertLoadedParameterDeclarationsUnchanged(): void
    {
        if ($this->plans->refreshLoaded() === $this->verifiedRevision) {
            return;
        }
        foreach ($this->completed as $completed) {
            $source = $completed['source'];
            $target = $source->target;
            if (!$target instanceof ReflectionParameter) {
                throw new \LogicException('Expected a consumed parameter plan.');
            }
            if (!$this->plans->hasSameLoadedDeclarations($target, $completed['plan'], $source->attributeOverride($completed['observed']))) {
                self::throwChanged($target, null);
            }
        }
    }

    private function recheckParameterClassifications(): bool
    {
        $checked = false;
        foreach ($this->parameterClassifications as $check) {
            $checked = $check() || $checked;
        }
        return $checked;
    }

    /** @param (Closure():void)|null $checkSources Loaded-only provenance check for the consumed arguments. */
    public function retainCompletedParameters(?Closure $checkSources): ?self
    {
        $this->checkParameterSources = $checkSources;
        return $this->completed === [] && $this->parameterClassifications === [] && $checkSources === null ? null : $this;
    }

    public function assertParameterSourcesUnchanged(): void
    {
        $this->checkParameterSources?->__invoke();
        while ($this->parameterClassifications !== [] && $this->recheckParameterClassifications()) {
            $this->assertLoadedParameterDeclarationsUnchanged();
            $this->checkParameterSources?->__invoke();
        }
    }

    /** @param ReflectionClass<covariant object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $target */
    private static function throwChanged(
        ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target,
        ?AttributePhase $phase,
    ): never {
        throw new AttributeCompositionException(sprintf(
            'Attribute composition for %s changed after %s completed. Load its attributes before execution.',
            self::targetName($target),
            $phase->name ?? 'parameter resolution',
        ));
    }

    /** @return list<array{AttributeDefinition,class-string,int}> */
    public static function semantics(AttributePlan $plan, ?AttributePhase $phase): array
    {
        return array_map(
            static fn(AttributeUsage $usage): array => [
                $usage->definition,
                $usage->attributeClass,
                $usage->declarationOrder,
            ],
            self::usages($plan, $phase),
        );
    }

    /** @return list<AttributeUsage> */
    private static function usages(AttributePlan $plan, ?AttributePhase $phase): array
    {
        if ($phase === null) {
            return $plan->usages;
        }
        return array_values(array_filter($plan->usages, static fn(AttributeUsage $usage): bool => self::appliesToPhase(
            $usage->definition,
            $usage->attributeClass,
            $phase,
            $plan->target instanceof ReflectionProperty,
        )));
    }

    /** @param class-string $attribute */
    public static function appliesToPhase(AttributeDefinition $definition, string $attribute, ?AttributePhase $phase, bool $property): bool
    {
        if ($phase === null) {
            return true;
        }
        // Proxy's property handler has no before-instantiation action.
        if ($phase === AttributePhase::BeforeInstantiation
            && $property
            && $definition->handler instanceof MakeHandler
            && is_a($attribute, Proxy::class, true)
        ) {
            return false;
        }
        if (!$definition->handler instanceof AttributeHandlerInterface) {
            return $phase === AttributePhase::AfterInstantiation;
        }
        return $definition->phase === $phase || $definition->phase === AttributePhase::Both;
    }

    /** @param ReflectionClass<covariant object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $target */
    private static function targetName(ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target): string
    {
        if ($target instanceof ReflectionClass) {
            return $target->getName();
        }
        if ($target instanceof ReflectionParameter) {
            $class = $target->getDeclaringClass();
            return sprintf('parameter $%s of %s%s()', $target->getName(), $class === null ? '' : $class->getName() . '::', $target->getDeclaringFunction()->getName());
        }
        return $target->getDeclaringClass()->getName() . '::' . $target->getName();
    }
}

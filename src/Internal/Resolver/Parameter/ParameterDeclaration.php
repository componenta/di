<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Parameter;

use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Internal\AttributePlans;
use Componenta\DI\Internal\BootstrapResolutionGuard;
use Componenta\DI\Internal\CompletedAttributeGuard;
use Componenta\DI\Internal\ReflectionSnapshot;
use ReflectionAttribute;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionParameter;
use WeakReference;

/** Owns a parameter's native declaration and its internal planning state. @internal */
final class ParameterDeclaration
{
    private ?AttributePlan $emptyClosurePlan = null;
    public private(set) ?ReflectionParameter $borrowedMetadata;
    public readonly bool $mayHaveAttributes;

    /** @var array<int,ReflectionAttribute<object>> */
    public private(set) array $attributeReflectors;
    /** @var array<class-string,true> */
    public private(set) array $attributesByClass;
    /** @var list<class-string> */
    public private(set) array $attributeClasses;

    /** @var list<ReflectionAttribute<object>>|null */
    public readonly ?array $capturedAttributes;
    private bool $selectedForResolution = false;

    /** @var array<int,ReflectionAttribute<object>>|null */
    public ?array $resolutionAttributes {
        get => $this->selectedForResolution ? $this->attributeReflectors : null;
    }

    /** @var class-string|null */
    public ?string $metadataDeclaringClassName {
        get => $this->metadataTarget()->getDeclaringClass()?->getName();
    }

    /** @param WeakReference<ReflectionParameter>|null $closureMethodSource */
    public function __construct(
        private readonly ReflectionParameter $declaration,
        ?ReflectionParameter $borrowedMetadata,
        private readonly ?bool $nativeOmission,
        public readonly bool $canCacheClassification,
        private readonly ?WeakReference $closureMethodSource,
    ) {
        // The native __invoke reflector does not expose the identity of its closure.
        $this->borrowedMetadata = $closureMethodSource === null ? $borrowedMetadata : null;
        $capture = $closureMethodSource !== null && $borrowedMetadata !== null;
        $attributes = $capture ? $this->captureClosureMethodAttributes($borrowedMetadata) : $this->readAttributes();
        $this->selectAttributes($attributes);
        $this->capturedAttributes = $capture ? array_values($this->attributeReflectors) : null;
        $this->mayHaveAttributes = $this->borrowedMetadata !== null || $attributes !== [];
    }

    public function snapshot(): ReflectionParameter
    {
        return ReflectionSnapshot::parameter($this->declaration);
    }

    public function declaringFunction(): ReflectionFunctionAbstract
    {
        return $this->declaration->getDeclaringFunction();
    }

    /** @phpstan-impure The caller may reinitialize its borrowed reflector between checks. */
    public function matchesDeclaration(ReflectionParameter $source): bool
    {
        return ReflectionSnapshot::sameParameter($source, $this->declaration);
    }

    public function defaultValue(): mixed
    {
        $source = $this->metadataTarget();
        $value = $source->getDefaultValue();
        return $source === $this->declaration || ReflectionSnapshot::sameParameter($source, $this->declaration)
            ? $value
            : $this->declaration->getDefaultValue();
    }

    public function releaseBorrowedMetadata(): void
    {
        $this->borrowedMetadata = null;
    }

    public function selectResolutionAttributes(): void
    {
        $this->selectAttributes($this->readAttributes());
        $this->selectedForResolution = true;
    }

    public function closureMethodSource(): ?ReflectionParameter
    {
        return $this->closureMethodSource?->get();
    }

    public function isNative(): bool
    {
        return $this->nativeOmission !== null;
    }

    public function isOmittable(bool $variadic): bool
    {
        if ($this->nativeOmission !== null || $variadic) {
            return $this->nativeOmission ?? false;
        }

        $target = $this->metadataTarget();
        return $target->isOptional() && $target->getDeclaringFunction()->isInternal();
    }

    public function attributePlan(AttributePlans $plans): AttributePlan
    {
        if ($this->selectedForResolution) {
            return $plans->build($this->declaration, $this->attributeReflectors);
        }
        if (!$this->mayHaveAttributes && !$this->canCacheClassification) {
            // Keep an empty closure plan local to the target and its captures.
            $plans->refreshEmptyUncachedTarget();
            return $this->emptyClosurePlan ??= new AttributePlan($this->declaration, []);
        }
        return $plans->build($this->metadataTarget(), $this->capturedAttributes);
    }

    public function recordReservedAttributes(CompletedAttributeGuard $completed, AttributePlan $plan): void
    {
        if ($this->mayHaveAttributes) {
            $completed->record($this->metadataTarget(), $plan, captured: $this->capturedAttributes);
        }
    }

    public function recordConsumedAttributes(
        CompletedAttributeGuard $completed,
        AttributePlan $plan,
        ?BootstrapResolutionGuard $bootstrap,
    ): void {
        if (!$this->mayHaveAttributes) {
            return;
        }

        $source = $this->borrowedMetadata ?? $this->declaration;
        $consumed = $this->resolutionAttributes;
        $completed->record($source, $plan, captured: $this->capturedAttributes, consumed: $consumed);
        $bootstrap?->recordAttributes($source, plan: $plan, captured: $this->capturedAttributes, consumed: $consumed);
    }

    /** Lets a derived reflector supply live metadata for the same declaration. */
    private function metadataTarget(): ReflectionParameter
    {
        $source = $this->borrowedMetadata;
        return $source !== null && $this->matchesDeclaration($source) ? $source : $this->declaration;
    }

    /** @return array<int,ReflectionAttribute<object>> */
    private function readAttributes(): array
    {
        $source = $this->metadataTarget();
        $attributes = $source->getAttributes();
        return $source === $this->declaration || ReflectionSnapshot::sameParameter($source, $this->declaration)
            ? $attributes
            : $this->declaration->getAttributes();
    }

    /** @return list<ReflectionAttribute<object>> */
    private function captureClosureMethodAttributes(ReflectionParameter $source): array
    {
        $attributes = $source->getAttributes();
        $current = ReflectionSnapshot::parameter($source, forPublication: true);
        $method = $current->getDeclaringFunction();
        $original = $this->declaration->getDeclaringFunction();
        // Scoped closures expose their declaring method but not their closure object.
        if ($method instanceof ReflectionMethod
            && $original instanceof ReflectionMethod
            && $method->getDeclaringClass()->getName() === $original->getDeclaringClass()->getName()
            && $method->getName() === $original->getName()
            && $current->getPosition() === $this->declaration->getPosition()
            && $current->getName() === $this->declaration->getName()
        ) {
            return array_values($attributes);
        }

        return array_values($this->declaration->getAttributes());
    }

    /** @param array<int,ReflectionAttribute<object>> $attributes */
    private function selectAttributes(array $attributes): void
    {
        $selection = [];
        $classes = [];
        foreach ($attributes as $index => $attribute) {
            /** @var class-string $class */
            $class = $attribute->getName();
            $selection[$index] = $attribute;
            $classes[$class] = true;
        }

        $this->attributeReflectors = $selection;
        $this->attributesByClass = $classes;
        $this->attributeClasses = array_keys($classes);
    }
}

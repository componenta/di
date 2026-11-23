<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Parameter;

use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Internal\AttributePlans;
use Componenta\DI\Internal\ReflectionSnapshot;
use ReflectionAttribute;
use ReflectionMethod;
use ReflectionParameter;
use WeakReference;

use function Componenta\DI\Internal\is_closure_reflector;

/** Owns a parameter's native declaration and its internal planning state. @internal */
final class ParameterDeclaration
{
    private ?AttributePlan $emptyClosurePlan = null;
    private readonly ReflectionParameter $declaration;
    public private(set) ?ReflectionParameter $borrowedMetadata;
    public readonly bool $mayHaveAttributes;

    /** @var list<ReflectionAttribute<object>>|null */
    public readonly ?array $capturedAttributes;

    /**
     * @param list<ReflectionAttribute<object>> $attributes
     * @param WeakReference<ReflectionParameter>|null $closureMethodSource
     */
    public function __construct(
        ReflectionParameter $reflection,
        private readonly ?bool $nativeOmission,
        public readonly bool $canCacheClassification,
        array $attributes,
        private readonly ?WeakReference $closureMethodSource,
    ) {
        if ($reflection::class === ReflectionParameter::class) {
            $this->declaration = $reflection;
            $this->borrowedMetadata = null;
            $this->capturedAttributes = null;
        } else {
            $this->declaration = ReflectionSnapshot::parameter($reflection, forPublication: true);
            $method = $this->declaration->getDeclaringFunction();
            // The native __invoke reflector does not expose the identity of its closure.
            $capture = $method instanceof ReflectionMethod && is_closure_reflector($method);
            $this->borrowedMetadata = $capture ? null : $reflection;
            $this->capturedAttributes = $capture ? $attributes : null;
        }
        $this->mayHaveAttributes = $this->borrowedMetadata !== null || $attributes !== [];
    }

    public function snapshot(): ReflectionParameter
    {
        return ReflectionSnapshot::parameter($this->declaration);
    }

    public function defaultValue(): mixed
    {
        return $this->metadataTarget()->getDefaultValue();
    }

    /** Lets a derived reflector supply live metadata for the same declaration. */
    public function metadataTarget(): ReflectionParameter
    {
        $source = $this->borrowedMetadata;
        return $source !== null && ReflectionSnapshot::sameParameter($source, $this->declaration)
            ? $source
            : $this->declaration;
    }

    public function releaseBorrowedMetadata(): void
    {
        $this->borrowedMetadata = null;
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
        if (!$this->mayHaveAttributes && !$this->canCacheClassification) {
            // Keep an empty closure plan local to the target and its captures.
            $plans->refreshEmptyUncachedTarget();
            return $this->emptyClosurePlan ??= new AttributePlan($this->declaration, []);
        }
        return $plans->build($this->metadataTarget(), $this->capturedAttributes);
    }
}

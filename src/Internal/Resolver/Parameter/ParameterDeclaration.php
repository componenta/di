<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Parameter;

use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Internal\AttributePlans;
use Componenta\DI\Internal\ReflectionSnapshot;
use ReflectionParameter;
use WeakReference;

/** Owns a parameter's native declaration and its internal planning state. @internal */
final class ParameterDeclaration
{
    private ?AttributePlan $emptyClosurePlan = null;

    /** @param WeakReference<ReflectionParameter>|null $closureMethodSource */
    public function __construct(
        private readonly ReflectionParameter $reflection,
        private readonly ?bool $nativeOmission,
        public readonly bool $canCacheClassification,
        public readonly bool $mayHaveAttributes,
        private readonly ?WeakReference $closureMethodSource,
    ) {}

    public function snapshot(): ReflectionParameter
    {
        return ReflectionSnapshot::parameter($this->reflection);
    }

    public function defaultValue(): mixed
    {
        return $this->reflection->getDefaultValue();
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
        return $this->nativeOmission ?? (!$variadic
            && $this->reflection->isOptional()
            && $this->reflection->getDeclaringFunction()->isInternal());
    }

    public function attributePlan(AttributePlans $plans): AttributePlan
    {
        if (!$this->mayHaveAttributes && !$this->canCacheClassification) {
            // Keep an empty closure plan local to the target and its captures.
            $plans->refreshEmptyUncachedTarget();
            return $this->emptyClosurePlan ??= new AttributePlan($this->reflection, []);
        }
        return $plans->build($this->reflection);
    }

}

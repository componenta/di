<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Componenta\DI\Attribute\Composition\AttributePlan;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;

/**
 * Keeps one declaration while a caller-owned reflector can still supply its metadata.
 *
 * @internal
 * @phpstan-type ReflectionTarget ReflectionClass<covariant object>|ReflectionMethod|ReflectionParameter|ReflectionProperty
 */
final readonly class AttributeMetadataSource
{
    /** @var ReflectionTarget */
    public ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target;
    /** @var ReflectionTarget|null */
    private ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty|null $borrowed;
    /** @var array<int,ReflectionAttribute<object>>|null */
    private ?array $captured;

    /**
     * @param ReflectionTarget $source
     * @param array<int,ReflectionAttribute<object>>|null $captured
     * @param array<int,ReflectionAttribute<object>>|null $consumed
     */
    public function __construct(
        ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $source,
        ?AttributePlan $plan = null,
        ?array $captured = null,
        private ?array $consumed = null,
    ) {
        $borrowed = ReflectionSnapshot::hasBorrowedMetadata($source);
        $this->target = $borrowed ? ($plan->target ?? ReflectionSnapshot::planDeclaration($source)) : $source;
        if ($borrowed && $captured === null && $this->target instanceof ReflectionParameter) {
            $method = $this->target->getDeclaringFunction();
            if ($method instanceof ReflectionMethod && is_closure_reflector($method)) {
                // A Closure::__invoke parameter does not reveal the originating closure's identity.
                $captured = $consumed ?? $source->getAttributes();
            }
        }
        $this->captured = $captured;
        $this->borrowed = $borrowed && $captured === null ? $source : null;
    }

    /** @return array<int,ReflectionAttribute<object>> */
    public function attributes(): array
    {
        return $this->attributeOverride() ?? $this->target->getAttributes();
    }

    /**
     * Supplies borrowed or captured declarations to the native target for validation.
     * @param array<int,ReflectionAttribute<object>>|null $fallback
     * @return array<int,ReflectionAttribute<object>>|null
     */
    public function attributeOverride(?array $fallback = null): ?array
    {
        $source = $this->borrowed;
        if ($source === null) {
            return $this->captured;
        }
        if (ReflectionSnapshot::sameDeclaration($source, $this->target)) {
            $attributes = $source->getAttributes();
            if (ReflectionSnapshot::sameDeclaration($source, $this->target)) {
                return $attributes;
            }
        }
        return $this->consumed ?? $fallback ?? $this->target->getAttributes();
    }
}

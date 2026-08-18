<?php

declare(strict_types=1);

namespace Componenta\DI\Object;

use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Resolver\Target\PropertyTarget;
use ReflectionProperty;

/** Precomputed value-resolution metadata for one non-promoted property. */
final readonly class PropertyValuePlan
{
    public function __construct(
        public ReflectionProperty $property,
        public PropertyTarget $target,
        public AttributePlan $plan,
    ) {}
}

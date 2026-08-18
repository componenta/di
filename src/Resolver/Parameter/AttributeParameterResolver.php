<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Attribute\Composition\Capability\ValueTransformer;
use Componenta\DI\ResolutionContext;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Value\ValueContext;
use Componenta\DI\Value\ValuePipeline;

/**
 * Transitional adapter: keeps the current v5 attribute-value implementation
 * behind ParameterResolverInterface while built-in parameter attributes are
 * migrated back to dedicated resolvers.
 *
 * @internal
 */
final readonly class AttributeParameterResolver implements ParameterResolverInterface
{
    public const int TRANSFORMER = 1;
    public const int PROVIDER = 2;
    public const int LEGACY_FALLBACK = 3;

    public function __construct(
        private AttributePlanBuilder $plans,
        private ValuePipeline $values,
        private int $mode,
    ) {}

    public function supports(ParameterTarget $target): bool
    {
        $plan = $this->plans->build($target->reflection);

        return match ($this->mode) {
            self::TRANSFORMER => $plan->has(ValueTransformer::class),
            self::PROVIDER => !$plan->has(ValueTransformer::class)
                && $plan->has(ValueProvider::class),
            self::LEGACY_FALLBACK => $plan->usages === [],
            default => false,
        };
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        $plan = $this->plans->build($target->reflection);
        $value = $this->values->resolve(
            $target,
            $plan,
            new ValueContext(
                new ResolutionContext(explicit: $context->provided),
                $context->resolved,
            ),
        );

        return [$target->position, $value];
    }
}

<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;

/**
 * Validates that a refreshed plan preserves the handlers already executed.
 *
 * @internal
 */
final class AttributeExecutionOrder
{
    /** @param array<int,AttributeUsage> $executed */
    public static function assertPrefix(AttributePlan $plan, array $executed): void
    {
        if ($executed === []) {
            return;
        }

        $ordered = [];
        foreach ($plan->usages as $usage) {
            if ($usage->definition->handler instanceof ParameterAttributeHandlerInterface) {
                $ordered[$usage->declarationOrder] = $usage;
            }
        }

        self::assertOrderedPrefix($ordered, $executed);
    }

    /**
     * @param array<int|string,AttributeUsage> $ordered
     * @param array<int|string,AttributeUsage> $executed
     */
    public static function assertOrderedPrefix(array $ordered, array $executed): void
    {
        $keys = array_keys($ordered);
        $index = 0;
        foreach ($executed as $key => $previous) {
            $currentKey = $keys[$index++] ?? null;
            $current = $currentKey === null ? null : $ordered[$currentKey];
            if ($current === null || $currentKey !== $key || !self::sameDeclaration($current, $previous)) {
                throw new AttributeCompositionException(sprintf(
                    'Attribute order for "%s" changed after #[%s] was already executed. Load its predecessors before execution.',
                    $previous->target->getName(),
                    $previous->attributeClass,
                ));
            }
        }
    }

    public static function assertMaterializedUsage(AttributeUsage $current, AttributeUsage $materialized): void
    {
        if (!self::sameDeclaration($current, $materialized)) {
            throw new AttributeCompositionException(sprintf(
                'Attribute declaration for "%s" changed after #[%s] was already instantiated. Load its attributes before execution.',
                $materialized->target->getName(),
                $materialized->attributeClass,
            ));
        }
    }

    /** @param array<class-string,AttributeUsage|null> $companions */
    public static function assertCompanionsUnchanged(AttributePlan $plan, array $companions): void
    {
        foreach ($companions as $attributeClass => $selected) {
            $current = $plan->attributes($attributeClass)[0] ?? null;
            if ($current === $selected || ($current !== null && $selected !== null && self::sameDeclaration($current, $selected))) {
                continue;
            }
            throw new AttributeCompositionException(sprintf(
                'Attribute companion %s for %s changed after it was selected for construction. Load its attributes before execution.',
                $attributeClass,
                describe_attribute_target($plan->target),
            ));
        }
    }

    private static function sameDeclaration(AttributeUsage $current, AttributeUsage $previous): bool
    {
        return $current->declarationOrder === $previous->declarationOrder
            && $current->definition === $previous->definition
            && $current->attributeClass === $previous->attributeClass;
    }

    private function __construct() {}
}

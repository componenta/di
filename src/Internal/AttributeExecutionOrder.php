<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;

/** Validates that a refreshed plan preserves the handlers already executed. @internal */
final class AttributeExecutionOrder
{
    /** @param array<int,AttributeUsage> $executed */
    public static function assertPrefix(AttributePlan $plan, array $executed, ?AttributePhase $phase = null): void
    {
        if ($executed === []) {
            return;
        }

        $ordered = [];
        foreach ($plan->usages as $usage) {
            $definition = $usage->definition;
            $applicable = $phase === null
                ? $definition->handler instanceof ParameterAttributeHandlerInterface
                : $definition->handler instanceof AttributeHandlerInterface
                    && ($definition->phase === $phase || $definition->phase === AttributePhase::Both);
            if ($applicable) {
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
            if ($current === null
                || $currentKey !== $key
                || $current->declarationOrder !== $previous->declarationOrder
                || $current->definition !== $previous->definition
            ) {
                throw new AttributeCompositionException(sprintf(
                    'Attribute order for "%s" changed after #[%s] was already executed. Load its predecessors before execution.',
                    $previous->target->getName(),
                    $previous->attributeClass,
                ));
            }
        }
    }

    private function __construct() {}
}

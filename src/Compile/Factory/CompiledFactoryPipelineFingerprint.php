<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Factory;

use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Value\ValueFallbackRegistry;

/** Stable digest of the semantic runtime consumed by generated entry shards. */
final class CompiledFactoryPipelineFingerprint
{
    public const int FORMAT_VERSION = 5;

    public static function calculate(
        AttributeDefinitionRegistry $attributes,
        ValueFallbackRegistry $fallbacks,
    ): string {
        $definitions = [];
        foreach ($attributes->definitions() as $definition) {
            $definitions[] = [
                'attribute' => $definition->attribute,
                'handler' => $definition->handler::class,
                'capabilities' => $definition->capabilities,
                'requires' => $definition->requires,
                'forbids' => $definition->forbids,
                'before' => $definition->before,
                'after' => $definition->after,
            ];
        }

        $policies = [];
        foreach ($attributes->policies() as $policy) {
            $policies[] = [$policy->capability, $policy->maxPerTarget];
        }

        $fallbackDefinitions = [];
        foreach ($fallbacks->definitions() as $definition) {
            $fallbackDefinitions[] = [
                $definition->id,
                $definition->fallback::class,
                $definition->before,
                $definition->after,
            ];
        }

        return hash('sha256', serialize([
            self::FORMAT_VERSION,
            $definitions,
            $policies,
            $fallbackDefinitions,
        ]));
    }

    private function __construct() {}
}

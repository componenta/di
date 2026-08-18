<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Factory;

use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Handler\VersionedAttributeHandlerInterface;
use Componenta\DI\Value\ValueFallbackRegistry;

/** Stable digest of the semantic runtime consumed by generated entry shards. */
final class CompiledFactoryPipelineFingerprint
{
    public const int FORMAT_VERSION = 6;

    public static function calculate(
        AttributeDefinitionRegistry $attributes,
        ValueFallbackRegistry $fallbacks,
    ): string {
        $definitions = [];
        foreach ($attributes->definitions() as $definition) {
            $definitions[] = [
                'attribute' => $definition->attribute,
                'definition_version' => $definition->version,
                'handler' => $definition->handler::class,
                'handler_version' => $definition->handler instanceof VersionedAttributeHandlerInterface
                    ? $definition->handler->semanticVersion()
                    : 1,
                'capabilities' => $definition->capabilities,
                'requires' => $definition->requires,
                'forbids' => $definition->forbids,
                'before' => $definition->before,
                'after' => $definition->after,
                'rules' => array_map(
                    static fn(object $rule): string => $rule::class,
                    $definition->rules,
                ),
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
            'compiler_format' => self::FORMAT_VERSION,
            'composition_format' => AttributePlanBuilder::FORMAT_VERSION,
            'definitions' => $definitions,
            'capability_policies' => $policies,
            'fallbacks' => $fallbackDefinitions,
        ]));
    }

    private function __construct() {}
}

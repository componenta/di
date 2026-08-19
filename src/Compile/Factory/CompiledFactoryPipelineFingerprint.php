<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Factory;

use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Resolver\Parameter\ParametersResolver;

/** Stable digest of the semantic runtime consumed by generated entry shards. */
final class CompiledFactoryPipelineFingerprint
{
    public const int FORMAT_VERSION = 7;

    public static function calculate(
        AttributeDefinitionRegistry $attributes,
        ParametersResolver $parameters,
    ): string {
        $definitions = [];
        foreach ($attributes->definitions() as $definition) {
            $definitions[] = [
                'attribute' => $definition->attribute,
                'definition_version' => $definition->version,
                'handler' => $definition->handler === null ? null : $definition->handler::class,
                'phase' => $definition->phase->value,
                'capabilities' => $definition->capabilities,
                'requires' => $definition->requires,
                'forbids' => $definition->forbids,
                'before' => $definition->before,
                'after' => $definition->after,
                'rules' => array_map(
                    static fn(object $rule): array => [
                        'class' => $rule::class,
                        'version' => self::semanticVersion($rule),
                    ],
                    $definition->rules,
                ),
            ];
        }

        $policies = [];
        foreach ($attributes->policies() as $policy) {
            $policies[] = [$policy->capability, $policy->maxPerTarget];
        }

        $resolvers = [];
        foreach ($parameters->semanticRegistrations() as $registration) {
            $resolver = $registration['resolver'];
            $resolvers[] = [
                'class' => $resolver::class,
                'priority' => $registration['priority'],
                'version' => self::semanticVersion($resolver),
            ];
        }

        return hash('sha256', serialize([
            'compiler_format' => self::FORMAT_VERSION,
            'composition_format' => AttributePlanBuilder::FORMAT_VERSION,
            'definitions' => $definitions,
            'capability_policies' => $policies,
            'parameter_resolvers' => $resolvers,
        ]));
    }

    private static function semanticVersion(object $extension): int|string
    {
        $constant = $extension::class . '::SEMANTIC_VERSION';
        $version = defined($constant) ? constant($constant) : 1;

        return is_int($version) || is_string($version) ? $version : 1;
    }

    private function __construct() {}
}

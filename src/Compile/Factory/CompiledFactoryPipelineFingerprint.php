<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Factory;

use Componenta\DI\Resolver\Attribute\AttributeHandlerRegistry;
use Componenta\DI\Resolver\Parameter\ParametersResolver;

/** Stable identity of the ordered runtime slots consumed by generated factories. */
final class CompiledFactoryPipelineFingerprint
{
    private const int FORMAT_VERSION = 3;

    public static function calculate(
        ParametersResolver $parameters,
        AttributeHandlerRegistry $attributes,
    ): string {
        $payload = [
            'format' => self::FORMAT_VERSION,
            'parameter_resolvers' => array_map(
                static fn(object $resolver): string => $resolver::class,
                $parameters->resolverList,
            ),
            'attribute_handlers' => array_map(
                static fn(array $registration): array => [
                    'class' => $registration['handler']::class,
                    'phase' => $registration['phase']->value,
                    'priority' => $registration['priority'],
                ],
                $attributes->registrations(),
            ),
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function __construct() {}
}

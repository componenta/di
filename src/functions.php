<?php

declare(strict_types=1);

namespace Componenta\DI;

use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Resolver\Target\ParameterTarget;
use ReflectionClass;

function normalize_env_name(string $name): string
{
    return strtoupper(preg_replace('/([a-z])([A-Z])/', '$1_$2', $name) ?? $name);
}

/** @param ReflectionClass<object> $class */
function is_entry_class_eligible(ReflectionClass $class): bool
{
    if ($class->isAnonymous()
        || $class->isInterface()
        || $class->isTrait()
        || $class->isAbstract()
        || $class->isEnum()
    ) {
        return false;
    }

    return $class->isInstantiable() || $class->isUserDefined();
}

/**
 * Executes one native operation while containing E_WARNING diagnostics.
 *
 * @template T
 * @param callable(): T $operation
 * @return T
 */
function with_suppressed_warnings(callable $operation): mixed
{
    set_error_handler(
        static fn(int $_severity, string $_message, string $_file, int $_line): bool => true,
        E_WARNING,
    );

    try {
        return $operation();
    } finally {
        restore_error_handler();
    }
}

/**
 * @param array<mixed> $result
 * @return array{0:int,1:mixed}
 */
function validate_parameter_resolution_result(
    array $result,
    ParameterResolverInterface $resolver,
    ParameterTarget $target,
    ParameterResolutionContext $context,
): array {
    if (array_keys($result) !== [0, 1]
        || !is_int($result[0])
        || $result[0] !== $target->position
    ) {
        throw ResolutionException::forParameter(
            $target->reflection,
            reason: sprintf(
                'resolver "%s" returned an invalid result; expected [position %d, value]',
                $resolver::class,
                $target->position,
            ),
            providedParameters: $context->provided,
            resolvedParameters: $context->resolved,
        );
    }

    if (!$target->accepts($result[1])) {
        throw ResolutionException::forParameter(
            $target->reflection,
            reason: sprintf(
                'resolver "%s" returned a value that does not satisfy the declared type',
                $resolver::class,
            ),
            providedParameters: $context->provided,
            resolvedParameters: $context->resolved,
        );
    }

    /** @var array{0:int,1:mixed} $result */
    return $result;
}

function compiled_factory_pipeline_fingerprint(
    AttributeDefinitionRegistry $attributes,
    ParametersResolver $parameters,
): string {
    $semanticVersion = static function (object $extension): int|string {
        $constant = $extension::class . '::SEMANTIC_VERSION';
        $version = defined($constant) ? constant($constant) : 1;

        return is_int($version) || is_string($version) ? $version : 1;
    };

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
                    'version' => $semanticVersion($rule),
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
            'version' => $semanticVersion($resolver),
        ];
    }

    return hash('sha256', serialize([
        'compiler_format' => 7,
        'composition_format' => AttributePlanBuilder::FORMAT_VERSION,
        'definitions' => $definitions,
        'capability_policies' => $policies,
        'parameter_resolvers' => $resolvers,
    ]));
}

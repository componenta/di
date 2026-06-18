<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

use Componenta\DI\Compile\AttributeMatcherInterface;
use Componenta\DI\Compile\CompilesPlanPayloadInterface;
use Componenta\DI\Compile\ParameterPlanResolverInterface;
use Componenta\DI\Compile\PlanPayloadValue;
use ReflectionParameter;
use ReflectionProperty;

/**
 * Resolves parameters using their declared default values.
 *
 * Near the end of the default chain. Returns the default value
 * if the parameter has one defined.
 *
 * @example
 * ```php
 * function paginate(int $page = 1, int $limit = 20) {}
 * // Returns 1 for $page, 20 for $limit if not provided
 * ```
 */
final class DefaultValueResolver implements
    ParameterResolverInterface,
    AttributeMatcherInterface,
    CompilesPlanPayloadInterface,
    ParameterPlanResolverInterface
{
    public const string KIND = 'componenta.di.default_value';

    public function planKind(): string
    {
        return self::KIND;
    }

    public function claimTarget(ReflectionParameter|ReflectionProperty $target): ?string
    {
        if (!$target instanceof ReflectionParameter) {
            return null;
        }
        return $target->isDefaultValueAvailable() ? self::KIND : null;
    }

    public function compilePayload(ReflectionParameter|ReflectionProperty $target): mixed
    {
        if (!$target instanceof ReflectionParameter || !$target->isDefaultValueAvailable()) {
            return null;
        }

        $defaultValue = $target->getDefaultValue();
        if (!PlanPayloadValue::isCacheable($defaultValue)) {
            return null;
        }

        return ['value' => $defaultValue];
    }

    public function resolveParameter(
        ReflectionParameter $parameter,
        array $providedParameters = [],
        array $resolvedParameters = [],
    ): ?array {
        if ($parameter->isDefaultValueAvailable()) {
            return [$parameter->getPosition(), $parameter->getDefaultValue()];
        }

        return null;
    }

    public function resolveParameterPlan(
        ReflectionParameter $parameter,
        mixed $payload,
        array $providedParameters = [],
        array $resolvedParameters = [],
    ): ?array {
        if (!is_array($payload) || !array_key_exists('value', $payload)) {
            return $this->resolveParameter($parameter, $providedParameters, $resolvedParameters);
        }

        return [$parameter->getPosition(), $payload['value']];
    }
}

<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

use Componenta\DI\Compile\AttributeMatcherInterface;
use Componenta\DI\Compile\CompilesPlanPayloadInterface;
use Componenta\DI\Compile\ParameterPlanResolverInterface;
use ReflectionParameter;
use ReflectionProperty;

/**
 * Resolves nullable parameters to null as a last resort.
 *
 * Last resolver in the default chain. Returns null for parameters
 * that allow null values, preventing resolution failure.
 *
 * @example
 * ```php
 * function process(?string $name, ?LoggerInterface $logger) {}
 * // Both resolve to null if nothing else matched
 * ```
 */
final class NullableResolver implements
    ParameterResolverInterface,
    AttributeMatcherInterface,
    CompilesPlanPayloadInterface,
    ParameterPlanResolverInterface
{
    public const string KIND = 'componenta.di.nullable';

    public function planKind(): string
    {
        return self::KIND;
    }

    public function claimTarget(ReflectionParameter|ReflectionProperty $target): ?string
    {
        if (!$target instanceof ReflectionParameter) {
            return null;
        }
        return $target->allowsNull() ? self::KIND : null;
    }

    public function compilePayload(ReflectionParameter|ReflectionProperty $target): mixed
    {
        return $target instanceof ReflectionParameter && $target->allowsNull();
    }

    public function resolveParameter(
        ReflectionParameter $parameter,
        array $providedParameters = [],
        array $resolvedParameters = [],
    ): ?array {
        if ($parameter->allowsNull()) {
            return [$parameter->getPosition(), null];
        }

        return null;
    }

    public function resolveParameterPlan(
        ReflectionParameter $parameter,
        mixed $payload,
        array $providedParameters = [],
        array $resolvedParameters = [],
    ): ?array {
        if ($payload !== true) {
            return $this->resolveParameter($parameter, $providedParameters, $resolvedParameters);
        }

        return [$parameter->getPosition(), null];
    }
}

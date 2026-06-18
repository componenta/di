<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver;

use Componenta\Caster\CasterProviderInterface;
use Componenta\Config\DefaultValue;
use Componenta\DI\Attribute\Cast;
use Componenta\DI\Compile\AttributeMatcherInterface;
use Componenta\DI\Compile\CompilesPlanPayloadInterface;
use Componenta\DI\Compile\ParameterPlanResolverInterface;
use Componenta\DI\Compile\PlanPayloadValue;
use Componenta\DI\Compile\PropertyPlanResolverInterface;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Property\PropertyResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Resolver\Target\PropertyTarget;
use Psr\Container\ContainerExceptionInterface;
use ReflectionParameter;
use ReflectionProperty;
use Throwable;

/**
 * Resolves parameters and properties using the {@see Cast} attribute for type
 * transformation.
 *
 * A single caster implementation is reused for both injection targets via the
 * {@see InjectionTargetInterface} abstraction. Provided/context values are
 * looked up by target name; when no value is supplied the resolver falls back
 * either to the cast attribute's own default or - for parameters only - to
 * the parameter's declared nullability / default value before giving up.
 *
 * @example
 * ```php
 * class UserDTO {
 *     public function __construct(
 *         #[Cast('int')] public int $age,
 *         #[Cast('datetime', default: 'now')] public \DateTimeInterface $createdAt,
 *     ) {}
 * }
 * ```
 */
final readonly class CastableResolver implements
    ParameterResolverInterface,
    PropertyResolverInterface,
    AttributeDrivenResolverInterface,
    AttributeMatcherInterface,
    CompilesPlanPayloadInterface,
    ParameterPlanResolverInterface,
    PropertyPlanResolverInterface
{
    public const string KIND = 'componenta.di.cast';

    public function __construct(
        private CasterProviderInterface $provider,
    ) {}

    public function planKind(): string
    {
        return self::KIND;
    }

    public function claimTarget(ReflectionParameter|ReflectionProperty $target): ?string
    {
        return $target->getAttributes(Cast::class) !== [] ? self::KIND : null;
    }

    public function compilePayload(ReflectionParameter|ReflectionProperty $target): mixed
    {
        $attribute = $target->getAttributes(Cast::class)[0] ?? null;
        if ($attribute === null) {
            return null;
        }

        /** @var Cast $cast */
        $cast = $attribute->newInstance();

        if (!PlanPayloadValue::isCacheable($cast->default)) {
            return null;
        }

        $hasParameterDefault = $target instanceof ReflectionParameter && $target->isDefaultValueAvailable();
        $parameterDefault = $hasParameterDefault ? $target->getDefaultValue() : null;

        if (!PlanPayloadValue::isCacheable($parameterDefault)) {
            return null;
        }

        $hasDefault = $cast->default !== DefaultValue::None;

        return [
            'name' => $target->getName(),
            'cast' => $cast->name,
            'hasDefault' => $hasDefault,
            'default' => $hasDefault ? $cast->default : null,
            'allowsNull' => $target instanceof ReflectionParameter && $target->allowsNull(),
            'hasParameterDefault' => $hasParameterDefault,
            'parameterDefault' => $parameterDefault,
        ];
    }

    public function resolveParameter(
        ReflectionParameter $parameter,
        array $providedParameters = [],
        array $resolvedParameters = [],
    ): ?array {
        $target = new ParameterTarget($parameter);
        $cast   = $target->getFirstAttribute(Cast::class);

        if ($cast === null) {
            return null;
        }

        return $this->resolveParameterCast(
            $parameter,
            $target->getName(),
            $cast->name,
            $cast->default !== DefaultValue::None,
            $cast->default,
            $target->allowsNull(),
            $target->isDefaultValueAvailable(),
            $target->isDefaultValueAvailable() ? $target->getDefaultValue() : null,
            $providedParameters,
            $resolvedParameters,
        );
    }

    public function resolveParameterPlan(
        ReflectionParameter $parameter,
        mixed $payload,
        array $providedParameters = [],
        array $resolvedParameters = [],
    ): ?array {
        $castPayload = $this->castPayload($payload);
        if ($castPayload === null) {
            return $this->resolveParameter($parameter, $providedParameters, $resolvedParameters);
        }

        return $this->resolveParameterCast(
            $parameter,
            $castPayload['name'],
            $castPayload['cast'],
            $castPayload['hasDefault'],
            $castPayload['default'],
            $castPayload['allowsNull'],
            $castPayload['hasParameterDefault'],
            $castPayload['parameterDefault'],
            $providedParameters,
            $resolvedParameters,
        );
    }

    public function resolveProperty(ReflectionProperty $property, array $context = []): ?array
    {
        $target = new PropertyTarget($property);
        $cast   = $target->getFirstAttribute(Cast::class);

        if ($cast === null) {
            return null;
        }

        return $this->resolvePropertyCast(
            $property,
            $target->getName(),
            $cast->name,
            $cast->default !== DefaultValue::None,
            $cast->default,
            $context,
        );
    }

    public function resolvePropertyPlan(
        ReflectionProperty $property,
        mixed $payload,
        array $context = [],
    ): ?array {
        $castPayload = $this->castPayload($payload);
        if ($castPayload === null) {
            return $this->resolveProperty($property, $context);
        }

        return $this->resolvePropertyCast(
            $property,
            $castPayload['name'],
            $castPayload['cast'],
            $castPayload['hasDefault'],
            $castPayload['default'],
            $context,
        );
    }

    /**
     * @param array<string|int, mixed> $providedParameters
     * @param array<int, mixed> $resolvedParameters
     * @return array{0: int, 1: mixed}
     */
    private function resolveParameterCast(
        ReflectionParameter $parameter,
        string $name,
        string $castName,
        bool $hasDefault,
        mixed $default,
        bool $allowsNull,
        bool $hasParameterDefault,
        mixed $parameterDefault,
        array $providedParameters,
        array $resolvedParameters,
    ): array {
        $hasValue = array_key_exists($name, $providedParameters);
        $value    = $providedParameters[$name] ?? null;

        if (!$hasValue && !$hasDefault) {
            if ($allowsNull) {
                return [$parameter->getPosition(), null];
            }

            if ($hasParameterDefault) {
                return [$parameter->getPosition(), $parameterDefault];
            }

            throw ResolutionException::forParameter(
                $parameter,
                reason: sprintf('missing required value for #[Cast("%s")]', $castName),
                providedParameters: $providedParameters,
                resolvedParameters: $resolvedParameters,
            );
        }

        $caster = $this->provider->provide($castName);
        if ($caster === null) {
            throw ResolutionException::forParameter(
                $parameter,
                reason: sprintf('caster "%s" is not registered', $castName),
                providedParameters: $providedParameters,
                resolvedParameters: $resolvedParameters,
            );
        }

        try {
            $input = $hasValue ? $value : $default;
            return [$parameter->getPosition(), $caster->cast($input)];
        } catch (ContainerExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forParameter(
                $parameter,
                previous: $e,
                providedParameters: $providedParameters,
                resolvedParameters: $resolvedParameters,
            );
        }
    }

    /**
     * @param array<string, mixed> $context
     * @return array{0: ReflectionProperty, 1: mixed}
     */
    private function resolvePropertyCast(
        ReflectionProperty $property,
        string $name,
        string $castName,
        bool $hasDefault,
        mixed $default,
        array $context,
    ): array {
        $hasValue = array_key_exists($name, $context);
        $value    = $context[$name] ?? null;

        if (!$hasValue && !$hasDefault) {
            throw ResolutionException::forProperty(
                $property,
                reason: sprintf('missing context key "%s"', $name),
            );
        }

        $caster = $this->provider->provide($castName);
        if ($caster === null) {
            throw ResolutionException::forProperty(
                $property,
                reason: sprintf('caster "%s" is not registered', $castName),
            );
        }

        try {
            $input = $hasValue ? $value : $default;
            return [$property, $caster->cast($input)];
        } catch (ContainerExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forProperty($property, previous: $e);
        }
    }

    /**
     * @return array{name: string, cast: string, hasDefault: bool, default: mixed, allowsNull: bool, hasParameterDefault: bool, parameterDefault: mixed}|null
     */
    private function castPayload(mixed $payload): ?array
    {
        if (!is_array($payload)) {
            return null;
        }

        if (
            !is_string($payload['name'] ?? null)
            || !is_string($payload['cast'] ?? null)
            || !is_bool($payload['hasDefault'] ?? null)
            || !array_key_exists('default', $payload)
            || !is_bool($payload['allowsNull'] ?? null)
            || !is_bool($payload['hasParameterDefault'] ?? null)
            || !array_key_exists('parameterDefault', $payload)
        ) {
            return null;
        }

        return $payload;
    }
}

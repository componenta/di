<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver;

use Componenta\Caster\CasterProviderInterface;
use Componenta\Config\DefaultValue;
use Componenta\DI\Attribute\Cast;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use LogicException;
use Psr\Container\ContainerExceptionInterface;
use ReflectionProperty;
use Reflector;
use Throwable;

/** Resolves #[Cast] parameters and handles #[Cast] properties. */
final class CastableResolver implements
    ParameterResolverInterface,
    AttributeHandlerInterface
{
    public AttributePhase $phase {
        get => AttributePhase::AfterInstantiation;
    }

    public int $priority {
        get => 900;
    }

    public function __construct(
        private readonly CasterProviderInterface $provider,
    ) {}

    public function supports(ParameterTarget $target): bool
    {
        return $target->hasAttribute(Cast::class);
    }

    public function supportsAttribute(string $attributeClass, Reflector $target): bool
    {
        return $target instanceof ReflectionProperty
            && is_a($attributeClass, Cast::class, true);
    }

    public function handle(
        object $attribute,
        Reflector $target,
        ObjectCreationContext $context,
    ): void {
        if (!$attribute instanceof Cast || !$target instanceof ReflectionProperty) {
            throw new LogicException('CastableResolver received an unsupported attribute target.');
        }

        if (!$context->claimProperty($target)) {
            return;
        }

        $value = $this->resolvePropertyCast(
            property: $target,
            name: $target->getName(),
            castName: $attribute->name,
            hasDefault: $attribute->default !== DefaultValue::None,
            default: $attribute->default,
            context: $context->parameters,
        );

        $context->writeProperty($target, $value);
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        $cast = $target->firstAttribute(Cast::class);
        if ($cast === null) {
            return null;
        }

        return $this->resolveParameterCast(
            target: $target,
            castName: $cast->name,
            hasDefault: $cast->default !== DefaultValue::None,
            default: $cast->default,
            providedParameters: $context->provided,
            resolvedParameters: $context->resolved,
        );
    }

    /**
     * @param array<string|int, mixed> $providedParameters
     * @param array<int, mixed>        $resolvedParameters
     * @return array{0: int, 1: mixed}
     */
    private function resolveParameterCast(
        ParameterTarget $target,
        string $castName,
        bool $hasDefault,
        mixed $default,
        array $providedParameters,
        array $resolvedParameters,
    ): array {
        $parameter = $target->reflection;
        $position = $target->position;
        $hasValue = array_key_exists($target->name, $providedParameters)
            || array_key_exists($position, $providedParameters);

        if (!$hasValue) {
            if ($hasDefault) {
                $value = $default;
            } elseif ($target->hasDefault) {
                return [$position, $target->default];
            } elseif ($target->allowsNull) {
                return [$position, null];
            } else {
                throw ResolutionException::forParameter(
                    $parameter,
                    reason: sprintf('missing required value for #[Cast("%s")]', $castName),
                    providedParameters: $providedParameters,
                    resolvedParameters: $resolvedParameters,
                );
            }
        } else {
            $value = array_key_exists($target->name, $providedParameters)
                ? $providedParameters[$target->name]
                : $providedParameters[$position];
        }

        try {
            $caster = $this->provider->provide($castName);
            if ($caster === null) {
                throw ResolutionException::forParameter(
                    $parameter,
                    reason: sprintf('caster "%s" is not registered', $castName),
                    providedParameters: $providedParameters,
                    resolvedParameters: $resolvedParameters,
                );
            }

            return [$position, $caster->cast($value)];
        } catch (ContainerExceptionInterface $e) {
            throw $e;
        } catch (ResolutionException $e) {
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

    /** @param array<string|int, mixed> $context */
    private function resolvePropertyCast(
        ReflectionProperty $property,
        string $name,
        string $castName,
        bool $hasDefault,
        mixed $default,
        array $context,
    ): mixed {
        $hasValue = array_key_exists($name, $context);
        if (!$hasValue && !$hasDefault) {
            throw ResolutionException::forProperty(
                $property,
                reason: sprintf('missing context key "%s"', $name),
            );
        }

        try {
            $caster = $this->provider->provide($castName);
            if ($caster === null) {
                throw ResolutionException::forProperty(
                    $property,
                    reason: sprintf('caster "%s" is not registered', $castName),
                );
            }

            return $caster->cast($hasValue ? $context[$name] : $default);
        } catch (ContainerExceptionInterface $e) {
            throw $e;
        } catch (ResolutionException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forProperty($property, previous: $e);
        }
    }
}

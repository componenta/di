<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver;

use Componenta\Caster\CasterProviderInterface;
use Componenta\Config\DefaultValue;
use Componenta\DI\Attribute\Cast;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use LogicException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use ReflectionProperty;
use Reflector;
use Throwable;

/** Resolves #[Cast] parameters and handles #[Cast] properties. */
final class CastableResolver implements ParameterResolverInterface, AttributeHandlerInterface
{
    public function __construct(private readonly ContainerInterface $container) {}

    public function supports(ParameterTarget $target): bool
    {
        return $target->hasAttribute(Cast::class);
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        $cast = $target->firstAttribute(Cast::class);
        if (!$cast instanceof Cast) {
            return null;
        }

        $hasValue = array_key_exists($target->name, $context->provided)
            || array_key_exists($target->position, $context->provided);

        if ($hasValue) {
            $value = array_key_exists($target->name, $context->provided)
                ? $context->provided[$target->name]
                : $context->provided[$target->position];
        } elseif ($cast->default !== DefaultValue::None) {
            $value = $cast->default;
        } elseif ($target->hasDefault) {
            return [$target->position, $target->default];
        } elseif ($target->allowsNull) {
            return [$target->position, null];
        } else {
            throw ResolutionException::forParameter(
                $target->reflection,
                reason: sprintf('missing required value for #[Cast("%s")]', $cast->name),
                providedParameters: $context->provided,
                resolvedParameters: $context->resolved,
            );
        }

        try {
            return [$target->position, $this->caster($cast->name)->cast($value)];
        } catch (ContainerExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forParameter(
                $target->reflection,
                previous: $e,
                providedParameters: $context->provided,
                resolvedParameters: $context->resolved,
            );
        }
    }

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        if (!$attribute instanceof Cast || !$target instanceof ReflectionProperty) {
            throw new LogicException('CastableResolver received an unsupported attribute target.');
        }
        if (!$context->claimProperty($target)) {
            return;
        }

        $name = $target->getName();
        $hasValue = array_key_exists($name, $context->parameters);
        if (!$hasValue && $attribute->default === DefaultValue::None) {
            throw ResolutionException::forProperty(
                $target,
                reason: sprintf('missing context key "%s"', $name),
            );
        }

        try {
            $value = $hasValue ? $context->parameters[$name] : $attribute->default;
            $context->writeProperty($target, $this->caster($attribute->name)->cast($value));
        } catch (ContainerExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forProperty($target, previous: $e);
        }
    }

    private function caster(string $name): object
    {
        $provider = $this->container->get(CasterProviderInterface::class);
        if (!$provider instanceof CasterProviderInterface) {
            throw new LogicException(sprintf(
                'Container entry %s must implement %s.',
                CasterProviderInterface::class,
                CasterProviderInterface::class,
            ));
        }

        $caster = $provider->provide($name);
        if ($caster === null) {
            throw new LogicException(sprintf('Caster "%s" is not registered.', $name));
        }
        return $caster;
    }
}

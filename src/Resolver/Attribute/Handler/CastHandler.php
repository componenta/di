<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Attribute\Handler;

use Componenta\Caster\CasterInterface;
use Componenta\Caster\CasterProviderInterface;
use Componenta\Config\DefaultValue;
use Componenta\DI\Attribute\Cast;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Parameter\ParameterAttributeValue;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Target\ParameterTarget;
use LogicException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use ReflectionProperty;
use Reflector;
use Throwable;

/** Handles #[Cast] on parameters and properties. */
final class CastHandler implements AttributeHandlerInterface, ParameterAttributeHandlerInterface
{
    public function __construct(private readonly ContainerInterface $container) {}

    public function resolveParameter(
        object $attribute,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        AttributePlan $plan,
        ParameterAttributeValue $value,
    ): ParameterAttributeValue {
        if (!$attribute instanceof Cast) {
            throw new LogicException('CastHandler received an unsupported parameter attribute.');
        }

        if (!$value->resolved) {
            if ($attribute->default !== DefaultValue::None) {
                $value = ParameterAttributeValue::resolved($attribute->default);
            } elseif ($target->hasDefault) {
                $value = ParameterAttributeValue::resolved($target->reflection->getDefaultValue());
            } elseif ($target->allowsNull) {
                $value = ParameterAttributeValue::resolved(null);
            } else {
                throw ResolutionException::forParameter(
                    $target->reflection,
                    reason: sprintf('missing required value for #[Cast("%s")]', $attribute->name),
                    providedParameters: $context->provided,
                    resolvedParameters: $context->resolved,
                );
            }
        }

        try {
            return ParameterAttributeValue::resolved(
                $this->caster($attribute->name)->cast($value->value),
            );
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
            throw new LogicException('CastHandler received an unsupported attribute target.');
        }

        try {
            if ($context->propertyClaimed($target)) {
                $context->writeProperty(
                    $target,
                    $this->caster($attribute->name)->cast($context->readProperty($target)),
                );
                return;
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

            $propertyValue = $hasValue ? $context->parameters[$name] : $attribute->default;
            $context->writeProperty($target, $this->caster($attribute->name)->cast($propertyValue));
        } catch (ContainerExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forProperty($target, previous: $e);
        }
    }

    private function caster(string $name): CasterInterface
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

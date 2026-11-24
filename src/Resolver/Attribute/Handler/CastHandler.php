<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Attribute\Handler;

use Closure;
use Componenta\Caster\CasterInterface;
use Componenta\Caster\CasterProviderInterface;
use Componenta\Config\DefaultValue;
use Componenta\DI\Attribute\Cast;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Internal\Resolver\Entry\ObjectCreationAccess;
use Componenta\DI\Internal\Resolver\Entry\PropertyValueHandlerScope;
use Componenta\DI\Internal\Resolver\Parameter\ParameterContextAccess;
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
final class CastHandler extends PropertyValueHandlerScope implements AttributeHandlerInterface, ParameterAttributeHandlerInterface
{
    public function __construct(private readonly ContainerInterface $container) {}

    /** @param (Closure():void)|null $beforeConsumption */
    public function resolveParameter(
        object $attribute,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        AttributePlan $plan,
        ParameterAttributeValue $value,
        ?Closure $beforeConsumption = null,
    ): ParameterAttributeValue {
        if (!$attribute instanceof Cast) {
            throw new LogicException('CastHandler received an unsupported parameter attribute.');
        }

        if (!$value->resolved) {
            if ($attribute->default !== DefaultValue::None) {
                $value = ParameterAttributeValue::resolved($attribute->default);
            } elseif ($target->variadic) {
                $value = ParameterAttributeValue::resolved([]);
            } elseif ($target->hasDefault) {
                $value = ParameterAttributeValue::resolved($target->defaultValue);
            } elseif ($target->allowsNull) {
                $value = ParameterAttributeValue::resolved(null);
            } else {
                throw ParameterContextAccess::failure(
                    $context,
                    $target->reflection,
                    reason: sprintf('missing required value for #[Cast("%s")]', $attribute->name),
                );
            }
        }

        try {
            $caster = $this->caster($attribute->name);
            // Provider construction and lookup can reveal a source before the
            // selected caster receives the already supplied parameter value.
            $beforeConsumption?->__invoke();
            return ParameterAttributeValue::resolved(
                $caster->cast($value->value),
            );
        } catch (ContainerExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ParameterContextAccess::failure(
                $context,
                $target->reflection,
                previous: $e,
            );
        }
    }

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        $this->handleProperty($attribute, $target, $context, null);
    }

    protected function handleProperty(
        object $attribute,
        Reflector $target,
        ObjectCreationContext $context,
        ?Closure $readArgument,
    ): void {
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
            $propertyValue = $attribute->default;
            $hasValue = $readArgument === null
                ? ObjectCreationAccess::tryReadParameter($context, $name, $propertyValue)
                : $readArgument($name, $propertyValue);
            if (!$hasValue && $propertyValue === DefaultValue::None) {
                throw ResolutionException::forProperty(
                    $target,
                    reason: sprintf('missing context key "%s"', $name),
                );
            }

            $caster = $this->caster($attribute->name);
            if ($hasValue) {
                // Preparing the caster may reveal a source after the value was read.
                ObjectCreationAccess::recheckParameters($context);
            }
            $context->writeProperty($target, $caster->cast($propertyValue));
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

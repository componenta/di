<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Attribute\Handler;

use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Transform;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Internal\AttributeCallbackInvoker;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Parameter\ParameterAttributeValue;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Target\ParameterTarget;
use LogicException;
use Psr\Container\ContainerExceptionInterface;
use ReflectionProperty;
use Reflector;
use Throwable;

/** Handles #[Transform] through the shared semantic-argument callback binder. */
final readonly class TransformHandler implements AttributeHandlerInterface, ParameterAttributeHandlerInterface
{
    public function __construct(private AttributeCallbackInvoker $callbacks) {}

    public function resolveParameter(
        object $attribute,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        AttributePlan $plan,
        ParameterAttributeValue $value,
    ): ParameterAttributeValue {
        if (!$attribute instanceof Transform) {
            throw new LogicException('TransformHandler received an unsupported parameter attribute.');
        }

        if (!$value->resolved) {
            if ($target->variadic) {
                $value = ParameterAttributeValue::resolved([]);
            } elseif ($target->hasDefault) {
                $value = ParameterAttributeValue::resolved($target->reflection->getDefaultValue());
            } elseif ($target->allowsNull) {
                $value = ParameterAttributeValue::resolved(null);
            } else {
                throw ResolutionException::forParameter(
                    $target->reflection,
                    reason: 'missing required value for #[Transform]',
                    providedParameters: $context->provided,
                    resolvedParameters: $context->resolved,
                );
            }
        }

        try {
            return ParameterAttributeValue::resolved($this->callbacks->invoke($attribute->callback, $value->value));
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
        if (!$attribute instanceof Transform || !$target instanceof ReflectionProperty) {
            throw new LogicException('TransformHandler received an unsupported attribute target.');
        }

        try {
            if ($context->propertyClaimed($target)) {
                $value = $context->readProperty($target);
            } else {
                if (!$context->claimProperty($target)) {
                    return;
                }

                $name = $target->getName();
                if (!array_key_exists($name, $context->parameters)) {
                    throw ResolutionException::forProperty(
                        $target,
                        reason: sprintf('missing context key "%s"', $name),
                    );
                }
                $value = $context->parameters[$name];
            }

            $context->writeProperty($target, $this->callbacks->invoke($attribute->callback, $value));
        } catch (ContainerExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forProperty($target, previous: $e);
        }
    }
}

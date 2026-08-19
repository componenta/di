<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Composition\Capability\AuthoritativeValueProvider;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;

/**
 * Single parameter-resolver bridge from composed attribute metadata to handlers.
 *
 * The resolver seeds caller-provided input when allowed, then executes every
 * parameter-aware handler in the already-composed plan order. Source handlers
 * may resolve an unresolved value; transformer handlers may update a resolved
 * value. Final type validation remains centralized in ParameterResolutionResult.
 */
final readonly class AttributeParameterResolver implements ParameterResolverInterface
{
    public function __construct(private AttributePlanBuilder $plans) {}

    public function supports(ParameterTarget $target): bool
    {
        return $this->hasHandler($this->plans->build($target->reflection));
    }

    /** @return array{0:int,1:mixed}|null */
    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        $plan = $this->plans->build($target->reflection);
        if (!$this->hasHandler($plan)) {
            return null;
        }

        $value = $this->initialValue($target, $context, $plan);

        foreach ($plan->usages as $usage) {
            $handler = $usage->definition->handler;
            if (!$handler instanceof ParameterAttributeHandlerInterface) {
                continue;
            }

            $value = $handler->resolveParameter(
                $usage->attribute,
                $target,
                $context,
                $plan,
                $value,
            );
        }

        return $value->resolved
            ? [$target->position, $value->value]
            : null;
    }

    private function hasHandler(AttributePlan $plan): bool
    {
        foreach ($plan->usages as $usage) {
            if ($usage->definition->handler instanceof ParameterAttributeHandlerInterface) {
                return true;
            }
        }

        return false;
    }

    private function initialValue(
        ParameterTarget $target,
        ParameterResolutionContext $context,
        AttributePlan $plan,
    ): ParameterAttributeValue {
        if ($this->hasAuthoritativeProvider($plan)) {
            return ParameterAttributeValue::unresolved();
        }

        if (array_key_exists($target->name, $context->provided)) {
            return ParameterAttributeValue::resolved($context->provided[$target->name]);
        }

        if (array_key_exists($target->position, $context->provided)) {
            return ParameterAttributeValue::resolved($context->provided[$target->position]);
        }

        foreach ($target->typeNames as $typeName) {
            if (!array_key_exists($typeName, $context->provided)) {
                continue;
            }

            $candidate = $context->provided[$typeName];
            if (is_object($candidate) && $target->accepts($candidate)) {
                return ParameterAttributeValue::resolved($candidate);
            }
        }

        return ParameterAttributeValue::unresolved();
    }

    private function hasAuthoritativeProvider(AttributePlan $plan): bool
    {
        foreach ($plan->usages as $usage) {
            foreach ($usage->definition->capabilities as $capability) {
                if (is_a($capability, AuthoritativeValueProvider::class, true)) {
                    return true;
                }
            }
        }

        return false;
    }
}

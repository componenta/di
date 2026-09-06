<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Composition\Capability\AuthoritativeValueProvider;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Internal\AttributeExecutionOrder;
use Componenta\DI\Internal\Resolver\Parameter\Request\RequestParameter;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;

/**
 * Single parameter-resolver bridge from composed attribute metadata to handlers.
 *
 * The resolver seeds caller-provided input when allowed, then executes every
 * parameter-aware handler in the already-composed plan order. Runtime handlers
 * receive isolated attribute instances; composition metadata remains immutable
 * and reusable across resolutions.
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

        $value = ParameterAttributeValue::unresolved();

        $authoritative = false;
        $processed = [];
        $pendingAttributes = [];
        while (true) {
            $revision = $this->plans->revision;
            $plan = $this->plans->build($target->reflection);
            AttributeExecutionOrder::assertPrefix($plan, $processed);

            $requiresAuthoritative = $plan->has(AuthoritativeValueProvider::class);
            if ($processed === []) {
                $authoritative = $requiresAuthoritative;
                $value = $this->initialValue($target, $context, $plan);
            } elseif ($authoritative !== $requiresAuthoritative) {
                throw new AttributeCompositionException(sprintf(
                    'Parameter $%s input policy changed after an attribute handler was already executed. Load its value sources before execution.',
                    $target->name,
                ));
            }

            foreach ($plan->usages as $usage) {
                $handler = $usage->definition->handler;
                if (isset($processed[$usage->declarationOrder])
                    || !$handler instanceof ParameterAttributeHandlerInterface
                ) {
                    continue;
                }

                $attribute = $pendingAttributes[$usage->declarationOrder] ??= $usage->newInstance();
                if ($this->plans->revision !== $revision) {
                    // A constructor can reveal a predecessor or a different input
                    // policy. Recompose before dispatch without reconstructing it.
                    continue 2;
                }
                $processed[$usage->declarationOrder] = $usage;
                $value = $handler->resolveParameter(
                    $attribute,
                    $target,
                    $context,
                    $plan,
                    $value,
                );
                unset($pendingAttributes[$usage->declarationOrder], $attribute);
                if ($this->plans->revision !== $revision) {
                    // Keep the resolved source and refresh only the remaining handlers.
                    continue 2;
                }
            }
            break;
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
        if ($plan->has(AuthoritativeValueProvider::class)) {
            return ParameterAttributeValue::unresolved();
        }

        if (isset($context->provided[$target->name]) || array_key_exists($target->name, $context->provided)) {
            return ParameterAttributeValue::resolved($context->provided[$target->name]);
        }

        if (isset($context->provided[$target->position]) || array_key_exists($target->position, $context->provided)) {
            return ParameterAttributeValue::resolved($context->provided[$target->position]);
        }

        foreach ($target->typeNames as $typeName) {
            if (RequestParameter::isTransportType($typeName)) {
                continue;
            }

            if (!isset($context->provided[$typeName]) || !array_key_exists($typeName, $context->provided)) {
                continue;
            }

            $candidate = $context->provided[$typeName];
            if (is_object($candidate)
                && $candidate instanceof $typeName
                && $target->accepts($candidate)
            ) {
                return ParameterAttributeValue::resolved($candidate);
            }
        }

        return ParameterAttributeValue::unresolved();
    }
}

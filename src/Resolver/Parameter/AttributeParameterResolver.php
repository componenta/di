<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;

/**
 * Single parameter-resolver bridge from composed attribute metadata to handlers.
 *
 * Attribute handlers never enter the parameter pipeline directly. The pipeline
 * sees one resolver; this class validates the plan, selects its parameter-aware
 * handler and delegates execution to that handler.
 */
final readonly class AttributeParameterResolver implements ParameterResolverInterface
{
    public function __construct(private AttributePlanBuilder $plans) {}

    public function supports(ParameterTarget $target): bool
    {
        return $this->handlers($this->plans->build($target->reflection)) !== [];
    }

    /** @return array{0:int,1:mixed}|null */
    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        $plan = $this->plans->build($target->reflection);
        $handlers = $this->handlers($plan);
        if ($handlers === []) {
            return null;
        }

        if (count($handlers) !== 1) {
            throw new AttributeCompositionException(sprintf(
                'Parameter $%s of %s resolves through multiple attribute handlers: %s.',
                $target->name,
                $target->declaringContext,
                implode(', ', array_map(
                    static fn(ParameterAttributeHandlerInterface $handler): string => $handler::class,
                    $handlers,
                )),
            ));
        }

        $handler = $handlers[0];
        foreach ($plan->usages as $usage) {
            if ($usage->definition->handler !== $handler) {
                continue;
            }

            return [
                $target->position,
                $handler->resolveParameter($usage->attribute, $target, $context, $plan),
            ];
        }

        return null;
    }

    /** @return list<ParameterAttributeHandlerInterface> */
    private function handlers(AttributePlan $plan): array
    {
        $handlers = [];
        $seen = [];

        foreach ($plan->usages as $usage) {
            $handler = $usage->definition->handler;
            if (!$handler instanceof ParameterAttributeHandlerInterface) {
                continue;
            }

            $id = spl_object_id($handler);
            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $handlers[] = $handler;
        }

        return $handlers;
    }
}

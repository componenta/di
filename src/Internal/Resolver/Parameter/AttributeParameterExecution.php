<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Parameter;

use Closure;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\Composition\Capability\AuthoritativeValueProvider;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Internal\AttributeExecutionOrder;
use Componenta\DI\Internal\AttributePlans;
use Componenta\DI\Internal\MakeHandlerAccess;
use Componenta\DI\Internal\Resolver\Parameter\Request\MappedRequestResultCapture;
use Componenta\DI\Internal\Resolver\Parameter\Request\RequestParameter;
use Componenta\DI\Internal\TransformHandler;
use Componenta\DI\Resolver\Attribute\Handler\CastHandler;
use Componenta\DI\Resolver\Attribute\Handler\MakeHandler;
use Componenta\DI\Resolver\Attribute\Handler\RequestAttributeHandler;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
use Componenta\DI\Resolver\Parameter\ParameterAttributeValue;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Target\ParameterTarget;

/**
 * Single parameter-resolver bridge from composed attribute metadata to handlers.
 *
 * The resolver seeds caller-provided input when allowed, then executes every
 * parameter-aware handler in the already-composed plan order. Runtime handlers
 * receive isolated attribute instances; composition metadata remains immutable
 * and reusable across resolutions.
 */
final readonly class AttributeParameterExecution
{
    public function __construct(private AttributePlans $plans) {}

    public function usesAttributePlans(AttributePlans $plans): bool
    {
        return $this->plans === $plans;
    }

    public function usesAttributeRegistry(AttributePlans $plans): bool
    {
        return $this->plans->sharesRegistryWith($plans);
    }

    public function supports(ParameterTarget $target): bool
    {
        return $this->hasHandler(ParameterTargetAccess::declaration($target)->attributePlan($this->plans));
    }

    /** @return array{0:int,1:mixed}|null */
    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        return $this->resolveParameterWithPlan($target, $context)['result'];
    }

    /**
     * @internal
     * @param (Closure():void)|null $beforeConsumption
     * @return array{result:array{0:int,1:mixed}|null,plan:AttributePlan}
     */
    public function resolveParameterWithPlan(
        ParameterTarget $target,
        ParameterResolutionContext $context,
        ?Closure $beforeConsumption = null,
        ?ParameterResolutionContext $literalContext = null,
    ): array {
        $declaration = ParameterTargetAccess::declaration($target);
        $plan = $declaration->attributePlan($this->plans);
        if (!$this->hasHandler($plan)) {
            return ['result' => null, 'plan' => $plan];
        }

        $value = ParameterAttributeValue::unresolved();

        $authoritative = false;
        $processed = [];
        $pendingAttributes = [];
        $companions = [];
        $sourceChecks = [];
        while (true) {
            $revision = $this->plans->refreshAvailability();
            $plan = $declaration->attributePlan($this->plans);
            AttributeExecutionOrder::assertPrefix($plan, $processed);
            if ($companions !== []) {
                AttributeExecutionOrder::assertCompanionsUnchanged($plan, $companions);
            }
            $beforeConsumption?->__invoke();

            $requiresAuthoritative = $plan->has(AuthoritativeValueProvider::class);
            if ($processed === []) {
                $authoritative = $requiresAuthoritative;
                $value = $authoritative
                    ? ParameterAttributeValue::unresolved()
                    : $this->initialValue($target, $literalContext ?? $context);
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

                $attribute = self::materialize($usage, $pendingAttributes);
                if ($this->plans->refreshAvailability() !== $revision) {
                    // A constructor can reveal a predecessor or a different input
                    // policy. Recompose before dispatch without reconstructing it.
                    $pendingAttributes[$usage->declarationOrder] = [$usage, $attribute];
                    continue 2;
                }
                $beforeConsumption?->__invoke();
                $processed[$usage->declarationOrder] = $usage;
                $mappedResults = null;
                if ($beforeConsumption !== null && ($handler instanceof TransformHandler || $handler instanceof CastHandler)) {
                    $value = $handler->resolveParameter(
                        $attribute,
                        $target,
                        $context,
                        $plan,
                        $value,
                        $beforeConsumption,
                    );
                } elseif ($handler instanceof MakeHandler) {
                    $value = MakeHandlerAccess::resolve(
                        $handler,
                        $attribute,
                        $target,
                        $context,
                        $plan,
                        $value,
                        static function (string $attributeClass) use ($plan, &$companions, &$pendingAttributes, &$processed, $handler): ?object {
                            $companion = $plan->attributes($attributeClass)[0] ?? null;
                            $companions[$attributeClass] = $companion;
                            if ($companion === null) {
                                return null;
                            }
                            if ($companion->definition->handler !== $handler) {
                                return $companion->newInstance();
                            }
                            $instance = self::materialize($companion, $pendingAttributes);
                            if (!isset($processed[$companion->declarationOrder])) {
                                $pendingAttributes[$companion->declarationOrder] = [$companion, $instance];
                            }
                            return $instance;
                        },
                    );
                } else {
                    $input = $handler instanceof RequestAttributeHandler ? $literalContext ?? $context : $context;
                    $execution = MappedRequestResultCapture::begin();
                    try {
                        $value = $handler->resolveParameter($attribute, $target, $input, $plan, $value);
                    } finally {
                        $mappedResults = MappedRequestResultCapture::end($execution);
                    }
                }
                unset($pendingAttributes[$usage->declarationOrder], $attribute);
                $currentRevision = $this->plans->refreshAvailability();
                foreach ($sourceChecks as $check) {
                    $check();
                }
                if ($mappedResults !== null) {
                    // Only mapped objects selected by the adapter can reach the callable.
                    $sourceChecks = [
                        ...$sourceChecks,
                        ...$mappedResults->finish(
                            is_object($value->value) || ($target->variadic && is_array($value->value)) ? $value->value : null,
                        ),
                    ];
                }
                if ($currentRevision !== $revision) {
                    // Keep the resolved source and refresh only the remaining handlers.
                    continue 2;
                }
            }
            break;
        }

        return [
            'result' => $value->resolved ? [$target->position, $value->value] : null,
            'plan' => $plan,
        ];
    }

    /** @param array<int, array{AttributeUsage, object}> $pendingAttributes */
    private static function materialize(AttributeUsage $usage, array $pendingAttributes): object
    {
        $pending = $pendingAttributes[$usage->declarationOrder] ?? null;
        if ($pending === null) {
            return $usage->newInstance();
        }
        AttributeExecutionOrder::assertMaterializedUsage($usage, $pending[0]);
        return $pending[1];
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
    ): ParameterAttributeValue {
        if ($target->variadic) {
            $provided = VariadicArguments::provided($target, $context);
            return $provided === null
                ? ParameterAttributeValue::unresolved()
                : ParameterAttributeValue::resolved($provided[1]);
        }

        $provided = ParameterContextAccess::provided($context);
        if (isset($provided[$target->name]) || array_key_exists($target->name, $provided)) {
            return ParameterAttributeValue::resolved($provided[$target->name]);
        }

        if (isset($provided[$target->position]) || array_key_exists($target->position, $provided)) {
            return ParameterAttributeValue::resolved($provided[$target->position]);
        }

        foreach ($target->typeNames as $typeName) {
            if (RequestParameter::isTransportType($typeName)) {
                continue;
            }

            if (!isset($provided[$typeName]) || !array_key_exists($typeName, $provided)) {
                continue;
            }

            $candidate = $provided[$typeName];
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

<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Attribute\Handler\MakeHandler;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;

/** Retains completed metadata only for the current resolution. @internal */
final class CompletedAttributeGuard
{
    /** @var list<array{target:ReflectionClass<object>|ReflectionMethod|ReflectionParameter|ReflectionProperty,plan:AttributePlan,phase:?AttributePhase}> */
    private array $completed = [];

    public function __construct(
        private readonly AttributePlanBuilder $plans,
        private int $verifiedRevision = -1,
    ) {}

    /** @param ReflectionClass<object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $target */
    public function record(
        ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target,
        AttributePlan $plan,
        ?AttributePhase $phase = null,
    ): void {
        if ($target->getAttributes() === []) {
            return;
        }
        $this->completed[] = ['target' => $target, 'plan' => $plan, 'phase' => $phase];
    }

    public function assertUnchanged(): void
    {
        while (($revision = $this->plans->revision) !== $this->verifiedRevision) {
            foreach ($this->completed as $completed) {
                $current = $this->plans->build($completed['target']);
                if (self::semantics($completed['plan'], $completed['phase']) !== self::semantics($current, $completed['phase'])) {
                    throw new AttributeCompositionException(sprintf(
                        'Attribute composition for %s changed after %s completed. Load its attributes before execution.',
                        self::targetName($completed['target']),
                        $completed['phase']->name ?? 'parameter resolution',
                    ));
                }
            }
            $this->verifiedRevision = $revision;
        }
    }

    /** @return list<array{AttributeDefinition,class-string,int}> */
    public static function semantics(AttributePlan $plan, ?AttributePhase $phase): array
    {
        return array_map(
            static fn(AttributeUsage $usage): array => [
                $usage->definition,
                $usage->attributeClass,
                $usage->declarationOrder,
            ],
            self::usages($plan, $phase),
        );
    }

    /** @return list<AttributeUsage> */
    private static function usages(AttributePlan $plan, ?AttributePhase $phase): array
    {
        if ($phase === null) {
            return $plan->usages;
        }
        return array_values(array_filter($plan->usages, static function (AttributeUsage $usage) use ($phase, $plan): bool {
            // The built-in Proxy definition serves class strategy and property
            // injection. Its property handler has no before-instantiation action.
            if ($phase === AttributePhase::BeforeInstantiation
                && $plan->target instanceof ReflectionProperty
                && $usage->definition->handler instanceof MakeHandler
                && $usage->is(Proxy::class)
            ) {
                return false;
            }
            if (!$usage->definition->handler instanceof AttributeHandlerInterface) {
                return $phase === AttributePhase::AfterInstantiation;
            }
            return $usage->definition->phase === $phase || $usage->definition->phase === AttributePhase::Both;
        }));
    }

    /** @param ReflectionClass<object>|ReflectionMethod|ReflectionParameter|ReflectionProperty $target */
    private static function targetName(ReflectionClass|ReflectionMethod|ReflectionParameter|ReflectionProperty $target): string
    {
        if ($target instanceof ReflectionClass) {
            return $target->getName();
        }
        if ($target instanceof ReflectionParameter) {
            $class = $target->getDeclaringClass();
            return sprintf('parameter $%s of %s%s()', $target->getName(), $class === null ? '' : $class->getName() . '::', $target->getDeclaringFunction()->getName());
        }
        return $target->getDeclaringClass()->getName() . '::' . $target->getName();
    }
}

<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Attribute\Handler\MakeHandler;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/** Tracks one object phase across targets without replaying dispatched handlers. @internal */
final class ObjectAttributeSequence
{
    private int $revision = -1;
    /** @var list<AttributePlan> */
    private array $plans = [];
    /** @var list<AttributeUsage> */
    private array $ordered = [];
    /** @var array<string,AttributeUsage> */
    private array $executed = [];
    /** @var array<int,int> Usage identity to stable target index within this phase. */
    private array $owners = [];
    /** @var array<int,array<int,object>> */
    private array $pending = [];

    /** @param list<ReflectionClass<object>|ReflectionProperty|ReflectionMethod> $targets */
    public function __construct(
        private readonly AttributePlanBuilder $builder,
        private readonly array $targets,
        private readonly AttributePhase $phase,
    ) {}

    public int $completed {
        get => count($this->executed);
    }

    public function next(): ?AttributeUsage
    {
        $this->refresh();
        return $this->ordered[$this->completed] ?? null;
    }

    /** @return ReflectionClass<object>|ReflectionProperty|ReflectionMethod */
    public function target(AttributeUsage $usage): ReflectionClass|ReflectionProperty|ReflectionMethod
    {
        return $this->targets[$this->owners[spl_object_id($usage)]];
    }

    public function instance(AttributeUsage $usage): object
    {
        return $this->pending[$this->owners[spl_object_id($usage)]][$usage->declarationOrder] ??= $usage->newInstance();
    }

    public function isCurrent(): bool
    {
        return $this->revision === $this->builder->revision;
    }

    public function dispatch(AttributeUsage $usage): void
    {
        $this->executed[$this->owners[spl_object_id($usage)] . ':' . $usage->declarationOrder] = $usage;
        unset($this->pending[$this->owners[spl_object_id($usage)]][$usage->declarationOrder]);
    }

    public function recordBootstrapUse(?BootstrapResolutionGuard $bootstrap): void
    {
        foreach ($this->plans as $index => $plan) {
            $bootstrap?->recordAttributes($this->targets[$index], $this->phase, $plan);
        }
    }

    public function completionGuard(): CompletedAttributeGuard
    {
        $guard = new CompletedAttributeGuard($this->builder, $this->revision);
        foreach ($this->plans as $index => $plan) {
            $guard->record($this->targets[$index], $plan, $this->phase);
        }
        return $guard;
    }

    private function refresh(): void
    {
        while (!$this->isCurrent()) {
            $revision = $this->builder->revision;
            $plans = [];
            $ordered = [];
            $owners = [];
            foreach ($this->targets as $index => $target) {
                $plan = $this->builder->build($target);
                $plans[] = $plan;
                foreach ($plan->usages as $usage) {
                    $definition = $usage->definition;
                    if ($definition->handler instanceof AttributeHandlerInterface
                        && ($definition->phase === $this->phase || $definition->phase === AttributePhase::Both)
                    ) {
                        $ordered[$index . ':' . $usage->declarationOrder] = $usage;
                        $owners[spl_object_id($usage)] = $index;
                    }
                }
            }
            if ($revision !== $this->builder->revision) {
                continue;
            }

            $ordered = $this->withoutInactivePredecessors($ordered);
            AttributeExecutionOrder::assertOrderedPrefix($ordered, $this->executed);
            $this->plans = $plans;
            $this->ordered = array_values($ordered);
            $this->owners = $owners;
            $this->revision = $revision;
        }
    }

    /**
     * @param array<string,AttributeUsage> $ordered
     * @return array<string,AttributeUsage>
     */
    private function withoutInactivePredecessors(array $ordered): array
    {
        if ($this->phase !== AttributePhase::BeforeInstantiation || $this->executed === []) {
            return $ordered;
        }

        $executed = array_keys($this->executed);
        $position = 0;
        foreach ($ordered as $key => $usage) {
            if (!isset($executed[$position])) {
                break;
            }
            if ($key === $executed[$position]) {
                ++$position;
                continue;
            }
            if (!isset($this->executed[$key])
                && $usage->target instanceof ReflectionProperty
                && $usage->definition->handler instanceof MakeHandler
                && $usage->is(Proxy::class)
            ) {
                // A newly discovered property Proxy has no before-phase action.
                // Its real injection still runs after construction. Preserve
                // argument materialization for every otherwise valid prefix.
                unset($ordered[$key]);
                continue;
            }
            break;
        }
        return $ordered;
    }
}

<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Exception\ExceptionInterface;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Attribute\Handler\MakeHandler;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Throwable;

/**
 * Tracks one object phase across targets without replaying dispatched handlers.
 *
 * @internal
 */
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
    /** @var array<int,array<int,array{AttributeUsage,object}>> */
    private array $pending = [];

    /** @param list<ReflectionClass<covariant object>|ReflectionProperty|ReflectionMethod> $targets */
    public function __construct(
        private readonly AttributePlans $builder,
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

    /** @return ReflectionClass<covariant object>|ReflectionProperty|ReflectionMethod */
    public function target(AttributeUsage $usage): ReflectionClass|ReflectionProperty|ReflectionMethod
    {
        return $this->targets[$this->owners[spl_object_id($usage)]];
    }

    public function instance(AttributeUsage $usage): object
    {
        return $this->materialize($usage, $this->owners[spl_object_id($usage)]);
    }

    public function companionInstance(AttributeUsage $active, AttributeUsage $companion): object
    {
        $owner = $this->owners[spl_object_id($active)];
        $attribute = $this->materialize($companion, $owner);
        $definition = $companion->definition;
        if ($definition->handler instanceof AttributeHandlerInterface
            && ($definition->phase === $this->phase || $definition->phase === AttributePhase::Both)
            && !isset($this->executed[$owner . ':' . $companion->declarationOrder])
        ) {
            // A companion may be consumed before its own step in this phase.
            $this->pending[$owner][$companion->declarationOrder] = [$companion, $attribute];
        }
        return $attribute;
    }

    public function retainInstance(AttributeUsage $usage, object $attribute): void
    {
        $this->pending[$this->owners[spl_object_id($usage)]][$usage->declarationOrder] = [$usage, $attribute];
    }

    private function materialize(AttributeUsage $usage, int $owner): object
    {
        $pending = $this->pending[$owner][$usage->declarationOrder] ?? null;
        if ($pending === null) {
            return $usage->newInstance();
        }

        AttributeExecutionOrder::assertMaterializedUsage($usage, $pending[0]);
        return $pending[1];
    }

    public function isCurrent(): bool
    {
        return $this->revision === $this->builder->refreshAvailability();
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
        $previousPlans = $this->plans;
        while (!$this->isCurrent()) {
            $revision = $this->builder->refreshAvailability();
            $plans = [];
            $ordered = [];
            $owners = [];
            foreach ($this->targets as $index => $target) {
                $plan = $this->builder->build($target, previousPlan: $previousPlans[$index] ?? null);
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
            $previousPlans = $plans;
            if ($revision !== $this->builder->refreshAvailability()) {
                continue;
            }

            $inactive = [];
            $ordered = $this->withoutInactivePredecessors($ordered, $inactive);
            AttributeExecutionOrder::assertOrderedPrefix($ordered, $this->executed);
            foreach ($inactive as $usage) {
                if (!$this->materializeInactivePredecessor($usage, $owners[spl_object_id($usage)], $revision)) {
                    continue 2;
                }
            }
            $this->plans = $plans;
            $this->ordered = array_values($ordered);
            $this->owners = $owners;
            $this->revision = $revision;
        }
    }

    /**
     * @param array<string,AttributeUsage> $ordered
     * @param list<AttributeUsage> $inactive
     * @return array<string,AttributeUsage>
     */
    private function withoutInactivePredecessors(array $ordered, array &$inactive): array
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
                // Its before handler is inactive, but native arguments still
                // run before construction, once the known prefix is valid.
                $inactive[] = $usage;
                unset($ordered[$key]);
                continue;
            }
            break;
        }
        return $ordered;
    }

    private function materializeInactivePredecessor(AttributeUsage $usage, int $owner, int $revision): bool
    {
        try {
            // The new owners snapshot is not published yet. Keep this instance
            // across discovery retries without replaying its native arguments.
            $this->pending[$owner][$usage->declarationOrder] = [$usage, $this->materialize($usage, $owner)];
            return $revision === $this->builder->refreshAvailability();
        } catch (ExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $target = $this->targets[$owner];
            if ($target instanceof ReflectionProperty) {
                throw ResolutionException::forProperty($target, previous: $exception);
            }
            throw $exception;
        }
    }
}

<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Parameter;

use Closure;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Exception\ExceptionInterface;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Internal\AttributePlans;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use stdClass;
use Throwable;
use WeakMap;

use function Componenta\DI\Internal\is_attribute_class_loaded;

/** Classifies targets and owns issuance and invalidation of prepared plans. @internal */
final class ParameterPlanBuilder
{
    /** @var WeakMap<ParameterTarget,list<int>>|null */
    private ?WeakMap $preparedSlots = null;
    /** @var WeakMap<ParameterTarget,list<string>>|null */
    private ?WeakMap $pendingAttributes = null;

    private int $attributeRevision = -1;
    private readonly object $owner;
    public private(set) int $revision = 0;

    public function __construct(
        private readonly AttributePlans $attributes,
        private readonly ParameterTargets $targets,
        private readonly ParameterResolverChain $chain,
    ) {
        $this->owner = new stdClass();
    }

    /** @param list<ParameterTarget> $targets */
    public function prepare(array $targets): PreparedParameterPlan
    {
        $this->synchronizeRevision();
        $revision = $this->revision;
        $selected = [];
        $requiresFreshValidation = false;
        foreach ($targets as $target) {
            // Autoload during classification can rebind a caller's reference.
            $selected[] = $target;
            $this->slotsFor(ParameterTargetAccess::forResolution($target), $targetRequiresFreshValidation);
            $requiresFreshValidation = $requiresFreshValidation || $targetRequiresFreshValidation;
        }

        return new PreparedParameterPlan($selected, $revision, $this->owner, $requiresFreshValidation);
    }

    public function assertOwned(PreparedParameterPlan $plan): void
    {
        if (!$plan->belongsTo($this->owner)) {
            throw new InvalidConfigurationException('Prepared parameter plan belongs to another resolver pipeline.');
        }
    }

    public function isCurrent(PreparedParameterPlan $plan): bool
    {
        $this->synchronizeRevision();

        return $this->chain->isSealed
            && !$plan->requiresFreshValidation
            && $plan->belongsTo($this->owner)
            && $plan->resolverRevision === $this->revision;
    }

    public function refresh(PreparedParameterPlan $plan): PreparedParameterPlan
    {
        $this->synchronizeRevision();

        if (!$plan->requiresFreshValidation && $plan->resolverRevision === $this->revision) {
            return $plan;
        }

        return $this->prepare($plan->targets);
    }

    /** @return list<string> */
    public function pendingAttributes(ParameterTarget $target): array
    {
        return $this->pendingAttributes[$target] ?? [];
    }

    /**
     * Rechecks a consumed resolver prefix when its recorded names become available.
     *
     * @param list<ParameterResolverInterface> $resolvers
     * @param list<int> $slots
     * @param list<string> $pending
     * @param (Closure():void)|null $beforeRejection
     * @return Closure():bool Whether a new name was checked.
     */
    public function watchCompletedSelection(
        ParameterTarget $target,
        array $resolvers,
        array $slots,
        ?int $winner,
        array $pending,
        ?Closure $beforeRejection,
    ): Closure {
        if ($winner !== null) {
            $resolvers = array_slice($resolvers, 0, $winner + 1);
            $slots = array_values(array_filter($slots, static fn(int $slot): bool => $slot <= $winner));
        }

        return function () use ($target, $resolvers, $slots, &$pending, $beforeRejection): bool {
            if (!array_any($pending, is_attribute_class_loaded(...))) {
                return false;
            }
            $remaining = array_filter($pending, static fn(string $name): bool => !is_attribute_class_loaded($name));
            [$current, $queries] = $this->targets->observeAttributeQueries(
                $target,
                fn(ParameterTarget $observed): array => $this->classify($observed, $resolvers),
            );
            if ($current !== $slots) {
                // Preserve a more specific invocation-boundary diagnostic.
                $beforeRejection?->__invoke();
                throw new AttributeCompositionException(sprintf(
                    'Attribute classification for parameter $%s of %s changed after resolution completed. Load its attributes before execution.',
                    $target->name,
                    $target->declaringContext,
                ));
            }

            $pending = [...$remaining, ...$queries];
            return true;
        };
    }

    public function clearClassifications(): void
    {
        $this->preparedSlots = null;
        $this->pendingAttributes = null;
    }

    public function chainChanged(): void
    {
        ++$this->revision;
        $this->clearClassifications();
    }

    /**
     * @param-out bool $requiresFreshValidation
     * @return list<int>
     */
    public function slotsFor(ParameterTarget $target, ?bool &$requiresFreshValidation = null): array
    {
        $requiresFreshValidation = false;
        $this->synchronizeRevision();

        $declaration = ParameterTargetAccess::declaration($target);
        $cacheable = $this->chain->isSealed && $declaration->canCacheClassification;
        if ($cacheable) {
            $cache = $this->preparedSlots ??= new WeakMap();
            if (isset($cache[$target])) {
                if (!isset($this->pendingAttributes[$target])
                    || !array_any($this->pendingAttributes[$target], is_attribute_class_loaded(...))
                ) {
                    return $cache[$target];
                }
                unset($cache[$target]);
            }
        }

        if ($target->byReference) {
            throw ResolutionException::forParameter(
                $target->reflection,
                reason: 'By-reference parameters are not supported by the DI resolver contract.',
            );
        }

        do {
            $revision = $this->revision;
            $attributePlan = $declaration->attributePlan($this->attributes);
            if ($target->attributeClasses === []) {
                $queries = [];
                $resolverSlots = $this->classify($target);
            } else {
                $unavailable = array_filter($target->attributeClasses, static fn(string $name): bool => !is_attribute_class_loaded($name));
                [$resolverSlots, $queries] = $this->targets->observeAttributeQueries($target, $this->classify(...));
                $queries = [...$unavailable, ...$queries];
            }
            // Autoload can reveal a handler while a resolver is being classified.
            $this->synchronizeRevision();
        } while ($revision !== $this->revision || ($queries !== [] && array_any($queries, is_attribute_class_loaded(...))));

        if ($queries !== []) {
            $pending = $this->pendingAttributes ??= new WeakMap();
            $pending[$target] = $queries;
        } elseif (isset($this->pendingAttributes[$target])) {
            unset($this->pendingAttributes[$target]);
        }

        // Late or mutable metadata can introduce a user rule on a later argument.
        $requiresFreshValidation = $this->attributes->hasCustomRules
            && ($this->attributes->requiresFreshValidation($attributePlan) || !$declaration->isNative());
        if (!$cacheable || $requiresFreshValidation) {
            return $resolverSlots;
        }

        $cache = $this->preparedSlots ??= new WeakMap();
        return $cache[$target] = $resolverSlots;
    }

    private function synchronizeRevision(): void
    {
        $attributeRevision = $this->attributes->refreshAvailability();
        if ($attributeRevision === $this->attributeRevision) {
            return;
        }

        $this->attributeRevision = $attributeRevision;
        ++$this->revision;
        $this->clearClassifications();
    }

    /**
     * @param list<ParameterResolverInterface>|null $resolvers
     * @return list<int>
     */
    private function classify(ParameterTarget $target, ?array $resolvers = null): array
    {
        $slots = [];
        $chainRevision = $this->chain->revision;

        try {
            foreach ($resolvers ?? $this->chain->resolvers as $slot => $resolver) {
                if ($resolver->supports($target)) {
                    $slots[] = $slot;
                }
            }
        } catch (ExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forParameter(
                $target->reflection,
                reason: 'parameter resolver classification failed',
                previous: $e,
            );
        }

        // Nested resolution may refresh attributes without changing the chain.
        if ($chainRevision !== $this->chain->revision) {
            throw new InvalidConfigurationException(
                'Parameter resolver supports() must not mutate the resolver chain.',
            );
        }

        return $slots;
    }
}

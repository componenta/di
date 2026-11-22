<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Parameter;

use Componenta\DI\Exception\ExceptionInterface;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Internal\AttributePlans;
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
    private ?WeakMap $pendingAttributeQueries = null;

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
            $this->slotsFor($target, $targetRequiresFreshValidation);
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
    public function pendingQueries(ParameterTarget $target): array
    {
        return $this->pendingAttributeQueries[$target] ?? [];
    }

    public function clearClassifications(): void
    {
        $this->preparedSlots = null;
        $this->pendingAttributeQueries = null;
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
                if (!isset($this->pendingAttributeQueries[$target])
                    || !array_any($this->pendingAttributeQueries[$target], is_attribute_class_loaded(...))
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
                [$resolverSlots, $queries] = $this->targets->observeAttributeQueries($target, $this->classify(...));
            }
            // Autoload can reveal a handler while a resolver is being classified.
            $this->synchronizeRevision();
        } while ($revision !== $this->revision || ($queries !== [] && array_any($queries, is_attribute_class_loaded(...))));

        if ($queries !== []) {
            $pending = $this->pendingAttributeQueries ??= new WeakMap();
            $pending[$target] = $queries;
        } elseif (isset($this->pendingAttributeQueries[$target])) {
            unset($this->pendingAttributeQueries[$target]);
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

    /** @return list<int> */
    private function classify(ParameterTarget $target): array
    {
        $slots = [];
        $chainRevision = $this->chain->revision;

        try {
            foreach ($this->chain->resolvers as $slot => $resolver) {
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

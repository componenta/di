<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Entry;

use Componenta\DI\Internal\AttributeExecution;
use Componenta\DI\Internal\Resolver\Parameter\ParameterExecution;
use Componenta\DI\Internal\Resolver\Parameter\ParameterTargetAccess;
use Componenta\DI\Internal\Resolver\Parameter\PreparedParameterPlan;
use ReflectionClass;
use ReflectionObject;

/**
 * Builds and caches class metadata and constructor parameter plans at one registry revision.
 *
 * @internal
 */
final class ObjectPlans
{
    /** @var array<string,ObjectMetadata> */
    private array $metadata = [];
    /** @var array<class-string,PreparedParameterPlan> */
    private array $constructorPlans = [];
    private int $metadataRevision = -1;

    public function __construct(
        private readonly ParameterExecution $parameters,
        private readonly AttributeExecution $attributes,
    ) {}

    /**
     * @template T of object
     * @param class-string<T>|ReflectionClass<T> $class
     */
    public function metadata(string|ReflectionClass $class): ObjectMetadata
    {
        $instanceReflection = null;
        if ($class instanceof ReflectionClass) {
            if ($class::class === ReflectionObject::class) {
                $instanceReflection = $class;
                $class = $class->getName();
            } elseif ($class::class === ReflectionClass::class) {
                $class = $class->getName();
            }
        }
        $lookupKey = is_string($class) && $instanceReflection === null
            ? strtolower(str_starts_with($class, '\\') ? substr($class, 1) : $class)
            : null;
        $plans = $this->attributes->plans;
        while (true) {
            $revision = $plans->refreshAvailability();
            if ($revision !== $this->metadataRevision) {
                $this->metadata = [];
                $this->constructorPlans = [];
                $this->metadataRevision = $revision;
            }

            if ($lookupKey !== null && isset($this->metadata[$lookupKey])) {
                return $this->metadata[$lookupKey];
            }

            /** @var ReflectionClass<object> $reflection */
            $reflection = is_string($class) ? new ReflectionClass($class) : $class;
            $canonicalKey = $lookupKey === null ? null : strtolower($reflection->getName());
            if ($canonicalKey !== null && $lookupKey !== null && isset($this->metadata[$canonicalKey])) {
                // Admission of a new alias may have loaded declarations.
                if ($plans->refreshAvailability() !== $revision) {
                    continue;
                }
                return $this->metadata[$lookupKey] = $this->metadata[$canonicalKey];
            }
            $classPlan = $plans->build($reflection);
            $requiresFreshValidation = $plans->requiresFreshValidation($classPlan);
            $constructor = $reflection->getConstructor();
            $constructorTargets = $constructor === null
                ? []
                : $this->parameters->targets($constructor->getParameters());

            foreach ($constructorTargets as $target) {
                $plan = ParameterTargetAccess::declaration($target)->attributePlan($plans);
                $requiresFreshValidation = $requiresFreshValidation || $plans->requiresFreshValidation($plan);
            }

            $attributePlan = $this->attributes->metadataState($reflection);
            if ($plans->refreshAvailability() !== $revision) {
                // Member discovery can load attributes used by an earlier target.
                // Only publish metadata assembled from one registry revision.
                continue;
            }

            $metadata = new ObjectMetadata(
                $reflection,
                $classPlan,
                $constructor,
                $constructorTargets,
                $attributePlan['hasHandlers'],
                $instanceReflection,
            );
            if ($canonicalKey !== null && $lookupKey !== null && !$requiresFreshValidation && !$attributePlan['requiresFreshValidation']) {
                $this->metadata[$canonicalKey] = $metadata;
                $this->metadata[$lookupKey] = $metadata;
            }
            return $metadata;
        }
    }

    public function constructorPlan(ObjectMetadata $metadata): PreparedParameterPlan
    {
        $name = $metadata->class->getName();
        $cacheable = $metadata->class::class === ReflectionClass::class;
        $cached = $cacheable ? ($this->constructorPlans[$name] ?? null) : null;
        if ($cached !== null && $this->parameters->isCurrentPlan($cached)) {
            return $cached;
        }

        $plan = $this->parameters->prepareTargets($metadata->constructorTargets);
        if ($cacheable && $this->parameters->isSealed && !$plan->requiresFreshValidation) {
            $this->constructorPlans[$name] = $plan;
        }

        return $plan;
    }
}

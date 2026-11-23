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
        $attributeSource = null;
        if ($class instanceof ReflectionClass) {
            if ($class::class !== ReflectionClass::class) {
                $attributeSource = $class;
            }
            $class = $class->getName();
        }
        $lookupKey = $attributeSource === null
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
            $reflection = new ReflectionClass($class);
            $canonicalKey = $lookupKey === null ? null : strtolower($reflection->getName());
            if ($canonicalKey !== null && $lookupKey !== null && isset($this->metadata[$canonicalKey])) {
                // Admission of a new alias may have loaded declarations.
                if ($plans->refreshAvailability() !== $revision) {
                    continue;
                }
                return $this->metadata[$lookupKey] = $this->metadata[$canonicalKey];
            }
            // Derived reflectors supply attribute metadata for the selected class.
            $attributeTarget = $attributeSource !== null && $attributeSource::class !== ReflectionObject::class
                && $attributeSource->getName() === $reflection->getName()
                ? $attributeSource
                : $reflection;
            $classPlan = $plans->build($attributeTarget);
            $requiresFreshValidation = $plans->requiresFreshValidation($classPlan);
            $constructor = $attributeTarget->getConstructor();
            $constructorTargets = $constructor === null
                ? []
                : $this->parameters->targets($constructor->getParameters());

            foreach ($constructorTargets as $target) {
                $plan = ParameterTargetAccess::declaration($target)->attributePlan($plans);
                $requiresFreshValidation = $requiresFreshValidation || $plans->requiresFreshValidation($plan);
            }

            $attributePlan = $this->attributes->metadataState($attributeTarget);
            if ($plans->refreshAvailability() !== $revision || $attributeTarget->getName() !== $reflection->getName()) {
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
                $attributeSource,
                nativeConstructorMetadata: $attributeTarget === $reflection,
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
        $cached = $metadata->nativeConstructorMetadata ? ($this->constructorPlans[$name] ?? null) : null;
        if ($cached !== null && $this->parameters->isCurrentPlan($cached)) {
            return $cached;
        }

        $plan = $this->parameters->prepareTargets($metadata->constructorTargets);
        if ($metadata->nativeConstructorMetadata && $this->parameters->isSealed && !$plan->requiresFreshValidation) {
            $this->constructorPlans[$name] = $plan;
        }

        return $plan;
    }
}

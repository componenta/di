<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Entry;

use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Internal\Resolver\Parameter\PreparedParameterPlan;
use Componenta\DI\Resolver\Attribute\AttributeProcessor;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use ReflectionClass;
use ReflectionObject;

/**
 * Builds and caches class metadata and constructor parameter plans at one registry revision.
 *
 * @internal
 */
final class ObjectPlans
{
    /** @var array<class-string,ObjectMetadata> */
    private array $metadata = [];
    /** @var array<class-string,PreparedParameterPlan> */
    private array $constructorPlans = [];
    private int $metadataRevision = -1;

    public function __construct(
        private readonly AttributePlanBuilder $plans,
        private readonly ParametersResolver $parameters,
        private readonly AttributeDefinitionRegistry $registry,
        private readonly AttributeProcessor $attributes,
    ) {}

    /**
     * @template T of object
     * @param class-string<T>|ReflectionClass<T> $class
     */
    public function metadata(string|ReflectionClass $class): ObjectMetadata
    {
        $cacheable = is_string($class) || $class::class === ReflectionClass::class;
        while (true) {
            $revision = $this->registry->revision;
            if ($revision !== $this->metadataRevision) {
                $this->metadata = [];
                $this->constructorPlans = [];
                $this->metadataRevision = $revision;
            }

            $name = $class instanceof ReflectionClass
                ? $class->getName()
                : ltrim($class, '\\');
            if ($cacheable && isset($this->metadata[$name])) {
                return $this->metadata[$name];
            }

            /** @var ReflectionClass<object> $reflection */
            $reflection = $class instanceof ReflectionClass
                ? $class
                : new ReflectionClass($class);
            $name = $reflection->getName();
            $classPlan = $this->plans->build($reflection);
            $requiresFreshValidation = $classPlan->requiresFreshValidation;
            $constructor = $reflection->getConstructor();
            $constructorTargets = $constructor === null
                ? []
                : $this->parameters->targets($constructor->getParameters());

            foreach ($constructorTargets as $target) {
                $plan = $this->plans->build($target->reflection);
                $requiresFreshValidation = $requiresFreshValidation || $plan->requiresFreshValidation;
            }

            $attributePlan = $this->attributes->executionPlan($reflection);
            if ($this->registry->revision !== $revision) {
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
            );
            if ($cacheable && !$requiresFreshValidation && !$attributePlan['requiresFreshValidation']) {
                $this->metadata[$name] = $metadata;
            }
            return $metadata;
        }
    }

    public function constructorPlan(ObjectMetadata $metadata): PreparedParameterPlan
    {
        $name = $metadata->class->getName();
        // Native ReflectionObject constructors still describe their class, but
        // a derived reflector may supply different or application-owned targets.
        $cacheable = $metadata->class::class === ReflectionClass::class
            || $metadata->class::class === ReflectionObject::class;
        $cached = $cacheable ? ($this->constructorPlans[$name] ?? null) : null;
        if ($cached !== null && $this->parameters->isCurrentPlan($cached)) {
            return $cached;
        }

        $plan = $this->parameters->prepareTargets($metadata->constructorTargets);
        if ($cacheable && $this->parameters->canCachePlans && !$plan->requiresFreshValidation) {
            $this->constructorPlans[$name] = $plan;
        }

        return $plan;
    }
}

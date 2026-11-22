<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Internal\Resolver\Entry\ObjectResolutionParameterStore;
use Componenta\DI\Object\ObjectPipeline;
use Componenta\DI\ProxyFactory;
use Componenta\DI\Resolver\Attribute\AttributeProcessor;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Resolver\Target\ParameterTargetFactory;

/**
 * Wires one shared set of resolver and attribute collaborators for an assembly.
 *
 * @internal
 */
final readonly class ResolutionRuntime
{
    public AttributeDefinitionRegistry $attributes;
    public AttributePlanBuilder $plans;
    public BootstrapResolutionGuard $bootstrapGuard;
    public ParametersResolver $parameters;
    public AttributeProcessor $attributeProcessor;
    public ProxyFactory $proxies;
    public ObjectResolutionParameterStore $resolutionParameters;
    public ObjectPipeline $objects;

    public function __construct(bool $targetedDiscovery = false)
    {
        $this->attributes = $targetedDiscovery
            ? AttributeDefinitionRegistry::withTargetedDiscovery()
            : new AttributeDefinitionRegistry();
        $this->plans = new AttributePlanBuilder($this->attributes);
        $targets = new ParameterTargetFactory();
        $this->bootstrapGuard = new BootstrapResolutionGuard($this->plans, $targets);
        $this->parameters = new ParametersResolver($this->plans, $targets, $this->bootstrapGuard);
        $this->attributeProcessor = new AttributeProcessor($this->attributes, $this->plans, $this->bootstrapGuard);
        $this->proxies = new ProxyFactory();
        $this->resolutionParameters = new ObjectResolutionParameterStore();
        $this->objects = new ObjectPipeline(
            $this->plans,
            $this->parameters,
            $this->proxies,
            $this->attributes,
            $this->resolutionParameters,
            $this->attributeProcessor,
        );
    }
}

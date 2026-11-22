<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Exception\InvalidConfigurationException;
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
final class ResolutionRuntime
{
    private bool $claimed = false;
    public readonly AttributeDefinitionRegistry $attributes;
    public readonly AttributePlanBuilder $plans;
    public readonly BootstrapResolutionGuard $bootstrapGuard;
    public readonly ParametersResolver $parameters;
    public readonly AttributeProcessor $attributeProcessor;
    public readonly ProxyFactory $proxies;
    public readonly ObjectPipeline $objects;

    public function __construct(bool $targetedDiscovery = false)
    {
        $this->attributes = new AttributeDefinitionRegistry($targetedDiscovery);
        $this->plans = new AttributePlanBuilder($this->attributes);
        $targets = new ParameterTargetFactory();
        $this->bootstrapGuard = AttributePlanAccess::observeBootstrap($this->plans, $targets);
        $this->parameters = new ParametersResolver($this->plans, $targets);
        $this->attributeProcessor = new AttributeProcessor($this->plans);
        $this->proxies = new ProxyFactory();
        $this->objects = new ObjectPipeline(
            $this->attributeProcessor,
            $this->parameters,
            $this->proxies,
        );
    }

    public function claim(): void
    {
        if ($this->claimed) {
            throw new InvalidConfigurationException('The resolution runtime already belongs to an assembly.');
        }
        $this->claimed = true;
    }

    private function __clone() {}
}

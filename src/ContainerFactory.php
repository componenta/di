<?php

declare(strict_types=1);

namespace Componenta\DI;

use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Componenta\Config\DependencyDefinitions;
use Componenta\DI\Internal\ContainerAssembly;

/** Builds a container from configuration and dependency definitions. */
final class ContainerFactory
{
    public function __construct() {}

    public function create(Config $config, DependencyDefinitions $dependencies): ContainerValue
    {
        return ContainerAssembly::assemble($config, $dependencies);
    }

    /** Limits autoload probes to the attributes declared by the current target. */
    public function createWithTargetedAttributeDiscovery(Config $config, DependencyDefinitions $dependencies): ContainerValue
    {
        return ContainerAssembly::assemble($config, $dependencies, targetedDiscovery: true);
    }
}

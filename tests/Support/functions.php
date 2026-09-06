<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Support;

use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;
use RuntimeException;

/** @param array<string, mixed> $sections */
function container(array $sections = [], ?Config $config = null): Container
{
    $value = (new ContainerFactory())->create(
        $config ?? new Config([], new Environment([])),
        new DependencyDefinitions($sections),
    );

    if (!$value->container instanceof Container) {
        throw new RuntimeException('DI factory returned an unsupported container implementation.');
    }

    return $value->container;
}

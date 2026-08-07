<?php

declare(strict_types=1);

use Componenta\DI\Container;
use Componenta\DI\ContainerBuilder;

if (!function_exists('minimalBuilder')) {
    /** Builder with the default parameter-resolver and attribute-handler pipelines. */
    function minimalBuilder(): ContainerBuilder
    {
        return new ContainerBuilder();
    }
}

if (!function_exists('minimalContainer')) {
    function minimalContainer(): Container
    {
        return minimalBuilder()->build();
    }
}

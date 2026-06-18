<?php

declare(strict_types=1);

use Componenta\DI\Container;
use Componenta\DI\ContainerBuilder;

if (!function_exists('minimalBuilder')) {
    /**
     * Builder with no extra services - the bare default chain (Array,
     * ArrayTyped, Make, Env, EntryId, Config, Autowire, DefaultValue,
     * Nullable for parameters; Array, Init, Make, Env, EntryId, Inject,
     * Config for properties) does not require Caster / Validation /
     * CurrentUser providers anymore.
     */
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

<?php

declare(strict_types=1);

use Componenta\Config\ConfigKey;
use Componenta\DI\ConfigProvider;
use Componenta\DI\Resolver\CastableResolver;
use Componenta\DI\Resolver\CurrentUserResolver;
use Componenta\DI\Resolver\Parameter\Request\RequestResolver;
use Componenta\DI\Resolver\Parameter\Request\RequestResolverFactory;

it('registers the lazy request resolver factory', function () {
    $config = (new ConfigProvider())();

    $dependencies = $config[ConfigKey::DEPENDENCIES];

    expect($dependencies[ConfigKey::FACTORIES][RequestResolver::class] ?? null)
        ->toBe(RequestResolverFactory::class)
        ->and($dependencies[ConfigKey::ATTRIBUTE_HANDLERS])
        ->toBe([CastableResolver::class, CurrentUserResolver::class])
        ->and(\Componenta\DI\ConfigKey::dependencyKeys())
        ->toBe(ConfigKey::dependencyKeys());
});

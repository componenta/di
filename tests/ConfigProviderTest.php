<?php

declare(strict_types=1);

use Componenta\Config\ConfigKey;
use Componenta\DI\ConfigProvider;
use Componenta\DI\Resolver\Parameter\Request\RequestResolver;
use Componenta\DI\Resolver\Parameter\Request\RequestResolverFactory;

it('registers the lazy request resolver factory', function () {
    $config = (new ConfigProvider())();

    expect($config[ConfigKey::DEPENDENCIES][ConfigKey::FACTORIES][RequestResolver::class] ?? null)
        ->toBe(RequestResolverFactory::class);
});

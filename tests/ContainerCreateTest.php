<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\DI\ConfigKey;
use Componenta\DI\Container;

it('creates a configured container through the public static entry point', function (): void {
    $container = Container::create(new Config([
        ConfigKey::DEPENDENCIES => [
            ConfigKey::SERVICES => [
                'container.create.value' => 'configured',
            ],
            ConfigKey::ALIASES => [
                'container.create.alias' => 'container.create.value',
            ],
        ],
    ]));

    expect($container)->toBeInstanceOf(Container::class)
        ->and($container->get('container.create.alias'))->toBe('configured');
});

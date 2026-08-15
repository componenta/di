<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Definition\DefinitionInterface;

final class ConfiguredStoredService implements DefinitionInterface
{
    public mixed $value {
        get => 'stored';
    }
}

test('services configuration preserves prebuilt values', function () {
    $service = new ConfiguredStoredService();
    $container = ContainerBuilder::configureWithDependencies(
        new Config([]),
        [ConfigKey::SERVICES => ['stored.service' => $service]],
    )->build();

    expect($container->get('stored.service'))->toBe($service);
});

<?php

declare(strict_types=1);

use Componenta\DI\Compile\Factory\CompiledFactoryDefinition;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\InvalidConfigurationException;

it('rejects compiled factory definitions whose file contains the cache separator', function (): void {
    $definition = new CompiledFactoryDefinition(
        "bad\0path",
        'GeneratedFactoryForBoundaryTest',
        'createService',
    );

    expect(fn() => (new ContainerBuilder())->build()->set('service', $definition))
        ->toThrow(InvalidConfigurationException::class);
});

it('rejects compiled factory definitions with an invalid method name at registration time', function (): void {
    $definition = new CompiledFactoryDefinition(
        '/tmp/generated-factory.php',
        'GeneratedFactoryForBoundaryTest',
        "create\0Service",
    );

    expect(fn() => (new ContainerBuilder())->build()->set('service', $definition))
        ->toThrow(InvalidConfigurationException::class);
});

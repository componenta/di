<?php

declare(strict_types=1);

use Componenta\DI\ContainerBuilder;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Tests\Fixture\ServiceWithMethods;

it('rejects malformed ClassDefinition method-call parameters at registration time', function (): void {
    $definition = new ClassDefinition(
        ServiceWithMethods::class,
        methodCalls: [[
            'method' => 'instanceMethod',
            'params' => 'not-an-array',
        ]],
    );

    expect(fn() => (new ContainerBuilder())->build()->set('service', $definition))
        ->toThrow(InvalidConfigurationException::class);
});

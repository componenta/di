<?php

declare(strict_types=1);

use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\InvalidConfigurationException;

it('rejects malformed runtime delegators at registration time', function (): void {
    $container = (new ContainerBuilder())->build();

    expect(fn() => $container->delegator('runtime.invalid', [new stdClass()]))
        ->toThrow(InvalidConfigurationException::class);
});

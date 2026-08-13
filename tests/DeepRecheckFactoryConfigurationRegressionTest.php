<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\InvalidConfigurationException;

test('factory configuration rejects malformed values using the internal compiled factory marker', function () {
    $malformed = "\0componenta.compiled-factory\0broken";

    expect(fn() => ContainerBuilder::configureWithDependencies(
        new Config([]),
        [ConfigKey::FACTORIES => ['service' => $malformed]],
    ))->toThrow(InvalidConfigurationException::class);
});

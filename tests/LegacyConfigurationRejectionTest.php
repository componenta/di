<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\InvalidConfigurationException;

it('rejects removed generated-entry-resolver configuration keys', function (string $key): void {
    $config = new Config([
        ConfigKey::DEPENDENCIES => [
            $key => 'legacy-value',
        ],
    ]);

    expect(fn() => ContainerBuilder::configure($config))
        ->toThrow(InvalidConfigurationException::class, $key);
})->with([
    'generated_entry_resolver_file',
    'generated_entry_resolver_release_fingerprint',
]);

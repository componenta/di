<?php

declare(strict_types=1);

require_once __DIR__ . '/Fixture/container_helpers.php';

use Componenta\DI\Definition\Definition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Resolver\Entry\InvokableResolver;

test('runtime invokable definitions enforce the configured non-empty shape', function () {
    expect(fn() => minimalContainer()->set('invalid.invokable', Definition::invokable('')))
        ->toThrow(InvalidConfigurationException::class, 'non-empty')
        ->and(fn() => new InvokableResolver(['']))
        ->toThrow(InvalidConfigurationException::class, 'non-empty');
});

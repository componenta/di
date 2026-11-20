<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ContainerBuilderInvokable;

use Componenta\Config\Config;
use Componenta\Config\ConfigKey;
use Componenta\Config\Environment;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Tests\Support\ContainerBuilder;

test('appending invokable fixtures preserves both existing sparse entries and the new entry', function (bool $missingExisting): void {
    $missing = __NAMESPACE__ . '\\MissingConfiguredInvokable';
    $builder = ContainerBuilder::configureWithDependencies(
        new Config([], new Environment([])),
        [ConfigKey::INVOKABLES => [1 => $missingExisting ? $missing : \stdClass::class]],
    );

    $builder->addInvokable($missingExisting ? \stdClass::class : $missing);

    expect(fn() => $builder->build())->toThrow(
        InvalidConfigurationException::class,
        'Invokable class "' . $missing . '" does not exist.',
    );
})->with(['existing missing entry' => true, 'new missing entry' => false]);

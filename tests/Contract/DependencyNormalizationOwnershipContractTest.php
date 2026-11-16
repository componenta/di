<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\DependencyNormalizationOwnership;

use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\Configuration\DependencyConfiguration;
use Componenta\DI\ContainerFactory;
use Componenta\DI\Exception\InvalidConfigurationException;
use stdClass;

test('reusing dependency definitions with referenced sections preserves keyed invokable aliases', function (): void {
    $invokables = ['configured-product' => stdClass::class];
    $definitions = new DependencyDefinitions(['invokables' => &$invokables]);
    $config = new Config([], new Environment([]));
    $factory = new ContainerFactory();

    $first = $factory->create($config, $definitions)->container;
    $second = $factory->create($config, $definitions)->container;

    expect($first->has('configured-product'))->toBeTrue()
        ->and($second->has('configured-product'))->toBeTrue()
        ->and($first->get('configured-product'))->toBeInstanceOf(stdClass::class)
        ->and($second->get('configured-product'))->toBeInstanceOf(stdClass::class)
        ->and($invokables)->toBe(['configured-product' => stdClass::class])
        ->and($definitions->sections)->toBe(['invokables' => ['configured-product' => stdClass::class]]);
});

test('value normalization does not overwrite referenced caller sections even when later validation fails', function (bool $invalid): void {
    $decorator = static fn(object $value): object => $value;
    $invokables = ['configured-product' => stdClass::class];
    $aliases = ['public-product' => 'configured-product'];
    $delegators = ['configured-product' => $decorator];
    $dependencies = [
        'invokables' => &$invokables,
        'aliases' => &$aliases,
        'delegators' => &$delegators,
    ];
    if ($invalid) {
        $dependencies['services'] = ['' => new stdClass()];
        expect(fn() => DependencyConfiguration::normalize($dependencies))
            ->toThrow(InvalidConfigurationException::class, 'Service ids must be non-empty strings.');
    } else {
        expect(DependencyConfiguration::normalize($dependencies))->toBe([
            'invokables' => [stdClass::class],
            'aliases' => ['public-product' => 'configured-product', 'configured-product' => stdClass::class],
            'delegators' => ['configured-product' => [$decorator]],
        ]);
    }

    expect($invokables)->toBe(['configured-product' => stdClass::class])
        ->and($aliases)->toBe(['public-product' => 'configured-product'])
        ->and($delegators)->toBe(['configured-product' => $decorator]);
})->with(['successful normalization' => false, 'failed validation' => true]);

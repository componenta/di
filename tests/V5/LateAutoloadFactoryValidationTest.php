<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\Config\Config;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\InvalidConfigurationException;

function lateFactoryClass(string $suffix): string
{
    return __NAMESPACE__ . '\\LateFactory_' . $suffix . '_' . bin2hex(random_bytes(5));
}

test('late-loaded static factories are validated against the runtime factory ABI', function (): void {
    $invalid = lateFactoryClass('Invalid');
    $invalidShort = substr($invalid, strrpos($invalid, '\\') + 1);
    $container = ContainerBuilder::configureWithDependencies(
        new Config([]),
        [
            ConfigKey::FACTORIES => [
                'late.invalid' => [$invalid, 'create'],
            ],
        ],
    )->build();

    eval(sprintf(
        'namespace %s; final class %s { public static function create(mixed $a, mixed $b, mixed $c): string { return "invalid"; } }',
        __NAMESPACE__,
        $invalidShort,
    ));

    expect(fn() => $container->get('late.invalid'))
        ->toThrow(InvalidConfigurationException::class, 'requires 3 arguments');
});

test('late-loaded static factories with a compatible ABI remain valid', function (): void {
    $valid = lateFactoryClass('Valid');
    $validShort = substr($valid, strrpos($valid, '\\') + 1);
    $container = ContainerBuilder::configureWithDependencies(
        new Config([]),
        [
            ConfigKey::FACTORIES => [
                'late.valid' => [$valid, 'create'],
            ],
        ],
    )->build();

    eval(sprintf(
        'namespace %s; final class %s { public static function create(\\Componenta\\Config\\ContainerValue $container, array $params): string { return $params["value"] ?? "default"; } }',
        __NAMESPACE__,
        $validShort,
    ));

    expect($container->make('late.valid', ['value' => 'ready']))->toBe('ready');
});

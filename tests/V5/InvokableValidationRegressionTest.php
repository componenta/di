<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\Config\Config;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Definition\Definition;
use Componenta\DI\Exception\InvalidConfigurationException;

final class AuditInvokableWithRequiredDependency
{
    public function __construct(public \stdClass $dependency) {}
}

abstract class AuditAbstractInvokable {}

test('config rejects unavailable invokable classes during build', function (): void {
    $config = new Config([
        ConfigKey::DEPENDENCIES => [
            ConfigKey::INVOKABLES => ['Componenta\\DI\\Tests\\V5\\MissingAuditInvokable'],
        ],
    ]);

    expect(fn() => ContainerBuilder::configure($config)->build())
        ->toThrow(InvalidConfigurationException::class, 'does not exist');
});

test('invokable classes must be directly constructible without required arguments', function (): void {
    expect(fn() => (new ContainerBuilder())
        ->addInvokable(AuditInvokableWithRequiredDependency::class)
        ->build())
        ->toThrow(InvalidConfigurationException::class, 'constructor requires arguments')
        ->and(fn() => (new ContainerBuilder())
            ->addInvokable(AuditAbstractInvokable::class)
            ->build())
        ->toThrow(InvalidConfigurationException::class, 'concrete and instantiable');
});

test('runtime InvokableDefinition updates use the same configuration validation', function (): void {
    $container = (new ContainerBuilder())->build();

    expect(fn() => $container->set(
        'audit.invokable',
        Definition::invokable(AuditInvokableWithRequiredDependency::class),
    ))->toThrow(InvalidConfigurationException::class, 'constructor requires arguments');
});

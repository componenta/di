<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\Config\Config;
use Componenta\Config\Environment;
use Componenta\DI\ConfigKey;
use Componenta\DI\Definition\Definition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Tests\Support\ContainerBuilder;

final class AuditInvokableWithRequiredDependency
{
    public function __construct(public \stdClass $dependency) {}
}

abstract class AuditAbstractInvokable {}

test('dependency definitions reject unavailable invokable classes during build', function (): void {
    $builder = ContainerBuilder::configureWithDependencies(
        new Config([], new Environment([])),
        [
            ConfigKey::INVOKABLES => ['Componenta\\DI\\Tests\\V5\\MissingAuditInvokable'],
        ],
    );

    expect(fn() => $builder->build())
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

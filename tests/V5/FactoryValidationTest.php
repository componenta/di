<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\Config\ContainerValue;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Psr\Container\ContainerInterface;

abstract class AbstractFactoryTarget {}

final class DefinitionMethodTarget
{
    private function hidden(): void {}
}

test('factory callable signatures reject arguments incompatible with the restored runtime ABI', function (): void {
    expect(fn() => (new ContainerBuilder())->addFactory(
        'bad.first',
        static fn(array $_container, array $_params): object => new \stdClass(),
    ))->toThrow(InvalidConfigurationException::class);

    expect(fn() => (new ContainerBuilder())->addFactory(
        'bad.second',
        static fn(ContainerValue $_container, string $_params): object => new \stdClass(),
    ))->toThrow(InvalidConfigurationException::class);
});

test('factory callable signatures accept ContainerValue or ContainerInterface plus array params', function (): void {
    $container = (new ContainerBuilder())
        ->addFactory(
            'value.factory',
            static fn(ContainerValue $_container, array $params): object => (object) $params,
        )
        ->addFactory(
            'interface.factory',
            static fn(ContainerInterface $_container, array $params): object => (object) $params,
        )
        ->build();

    expect($container->make('value.factory', ['value' => 1])->value)->toBe(1)
        ->and($container->make('interface.factory', ['value' => 2])->value)->toBe(2);
});

test('internal factories that cannot accept both runtime arguments fail before first resolution', function (): void {
    $container = (new ContainerBuilder())
        ->addFactories(['bad.internal' => 'strlen'])
        ->build();

    expect(fn() => $container->make('bad.internal'))
        ->toThrow(InvalidConfigurationException::class);
});

test('ClassDefinition validates target instantiability and configured method visibility', function (): void {
    expect(fn() => (new ContainerBuilder())->addDefinition(
        'abstract.target',
        ClassDefinition::create(AbstractFactoryTarget::class),
    ))->toThrow(InvalidConfigurationException::class);

    expect(fn() => (new ContainerBuilder())->addDefinition(
        'hidden.method',
        ClassDefinition::create(DefinitionMethodTarget::class)->method('hidden'),
    ))->toThrow(InvalidConfigurationException::class);
});

<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\Config\ContainerValue;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\ResolutionContext;

abstract class AbstractFactoryTarget {}

final class DefinitionMethodTarget
{
    private function hidden(): void {}
}

test('factory callable signatures are rejected during composition when the v5 runtime ABI cannot call them', function (): void {
    expect(fn() => (new ContainerBuilder())->addFactory(
        'bad.first',
        static fn(array $_container, ResolutionContext $_context): object => new \stdClass(),
    ))->toThrow(InvalidConfigurationException::class);

    expect(fn() => (new ContainerBuilder())->addFactory(
        'bad.second',
        static fn(ContainerValue $_container, array $_context): object => new \stdClass(),
    ))->toThrow(InvalidConfigurationException::class);
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

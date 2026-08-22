<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\LazyServiceFactoryInterface;
use Componenta\DI\ProxyFactoryInterface;
use Psr\Container\ContainerInterface;

abstract class AbstractFactoryTarget {}

final class DefinitionMethodTarget
{
    private function hidden(): void {}
}

final class StandaloneLazyServiceFactory implements LazyServiceFactoryInterface
{
    /** @var array<string|int,mixed> */
    public array $seenContext = [];

    public function lazy(
        ContainerInterface $container,
        ProxyFactoryInterface $proxyFactory,
        array $context = [],
    ): object {
        $this->seenContext = $context;

        return (object) [
            'container' => $container,
            'context' => $context,
        ];
    }
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

test('standalone lazy service factories do not need to be callable', function (): void {
    $direct = new StandaloneLazyServiceFactory();
    $directContainer = ContainerBuilder::configure(new Config([
        ConfigKey::DEPENDENCIES => [
            ConfigKey::FACTORIES => [
                'lazy.direct' => $direct,
            ],
        ],
    ]))->build();

    $directResult = $directContainer->make('lazy.direct', ['source' => 'direct']);

    $deferred = new StandaloneLazyServiceFactory();
    $deferredContainer = ContainerBuilder::configure(new Config([
        ConfigKey::DEPENDENCIES => [
            ConfigKey::SERVICES => [
                'lazy.factory' => $deferred,
            ],
            ConfigKey::FACTORIES => [
                'lazy.deferred' => 'lazy.factory',
            ],
        ],
    ]))->build();

    $deferredResult = $deferredContainer->make('lazy.deferred', ['source' => 'service']);

    expect($directResult->container)->toBeInstanceOf(ContainerValue::class)
        ->and($directResult->context)->toBe(['source' => 'direct'])
        ->and($direct->seenContext)->toBe(['source' => 'direct'])
        ->and($deferredResult->container)->toBeInstanceOf(ContainerValue::class)
        ->and($deferredResult->context)->toBe(['source' => 'service'])
        ->and($deferred->seenContext)->toBe(['source' => 'service']);
});

test('internal factories that cannot accept both runtime arguments fail before first resolution', function (): void {
    $container = (new ContainerBuilder())
        ->addFactories(['bad.internal' => 'strlen'])
        ->build();

    expect(fn() => $container->make('bad.internal'))
        ->toThrow(InvalidConfigurationException::class);
});

test('ClassDefinition validates target eligibility and configured method visibility', function (): void {
    expect(fn() => (new ContainerBuilder())->addDefinition(
        'abstract.target',
        ClassDefinition::create(AbstractFactoryTarget::class),
    ))->toThrow(InvalidConfigurationException::class);

    expect(fn() => (new ContainerBuilder())->addDefinition(
        'hidden.method',
        ClassDefinition::create(DefinitionMethodTarget::class)->method('hidden'),
    ))->toThrow(InvalidConfigurationException::class);
});

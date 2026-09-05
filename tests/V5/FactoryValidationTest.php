<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Componenta\Config\Environment;
use Componenta\DI\ConfigKey;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\LazyServiceFactoryInterface;
use Componenta\DI\ProxyFactoryInterface;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Psr\Container\ContainerInterface;

abstract class AbstractFactoryTarget {}

final class DefinitionMethodTarget
{
    private function hidden(): void {}

    public function callHidden(): void
    {
        $this->hidden();
    }
}

final readonly class FactoryValueResult
{
    public function __construct(public int $value) {}
}

final readonly class StandaloneLazyResult
{
    /** @param array<string|int,mixed> $context */
    public function __construct(
        public ContainerInterface $container,
        public array $context,
    ) {}
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

        return new StandaloneLazyResult($container, $context);
    }
}

test('factory callable signatures reject arguments incompatible with the restored runtime ABI', function (): void {
    expect(fn() => (new ContainerBuilder())->addFactory(
        'bad.first',
        static fn(array $_container, array $_params): object => new \stdClass(),
    )->build())->toThrow(InvalidConfigurationException::class);

    expect(fn() => (new ContainerBuilder())->addFactory(
        'bad.second',
        static fn(ContainerValue $_container, string $_params): object => new \stdClass(),
    )->build())->toThrow(InvalidConfigurationException::class);
});

test('factory callable signatures accept ContainerValue or ContainerInterface plus array params', function (): void {
    $container = (new ContainerBuilder())
        ->addFactory(
            'value.factory',
            static fn(ContainerValue $_container, array $params): object => new FactoryValueResult(
                is_int($params['value'] ?? null) ? $params['value'] : 0,
            ),
        )
        ->addFactory(
            'interface.factory',
            static fn(ContainerInterface $_container, array $params): object => new FactoryValueResult(
                is_int($params['value'] ?? null) ? $params['value'] : 0,
            ),
        )
        ->build();

    $valueResult = $container->make('value.factory', ['value' => 1]);
    $interfaceResult = $container->make('interface.factory', ['value' => 2]);
    if (!$valueResult instanceof FactoryValueResult || !$interfaceResult instanceof FactoryValueResult) {
        throw new \LogicException('The factory ABI fixture returned an unexpected type.');
    }

    expect($valueResult->value)->toBe(1)
        ->and($interfaceResult->value)->toBe(2);
});

test('standalone lazy service factories do not need to be callable', function (): void {
    $fluent = new StandaloneLazyServiceFactory();
    $bulk = new StandaloneLazyServiceFactory();
    $fluentContainer = (new ContainerBuilder())
        ->addFactory('lazy.fluent', $fluent)
        ->addFactories(['lazy.bulk' => $bulk])
        ->build();

    $fluentResult = $fluentContainer->make('lazy.fluent', ['source' => 'fluent']);
    $bulkResult = $fluentContainer->make('lazy.bulk', ['source' => 'bulk']);
    if (!$fluentResult instanceof StandaloneLazyResult || !$bulkResult instanceof StandaloneLazyResult) {
        throw new \LogicException('The configured lazy factory returned an unexpected type.');
    }

    $direct = new StandaloneLazyServiceFactory();
    $directContainer = ContainerBuilder::configureWithDependencies(
        new Config([], new Environment([])),
        [
            ConfigKey::FACTORIES => [
                'lazy.direct' => $direct,
            ],
        ],
    )->build();

    $directResult = $directContainer->make('lazy.direct', ['source' => 'direct']);
    if (!$directResult instanceof StandaloneLazyResult) {
        throw new \LogicException('The direct lazy factory returned an unexpected type.');
    }

    $deferred = new StandaloneLazyServiceFactory();
    $deferredContainer = ContainerBuilder::configureWithDependencies(
        new Config([], new Environment([])),
        [
            ConfigKey::SERVICES => [
                'lazy.factory' => $deferred,
            ],
            ConfigKey::FACTORIES => [
                'lazy.deferred' => 'lazy.factory',
            ],
        ],
    )->build();

    $deferredResult = $deferredContainer->make('lazy.deferred', ['source' => 'service']);
    if (!$deferredResult instanceof StandaloneLazyResult) {
        throw new \LogicException('The deferred lazy factory returned an unexpected type.');
    }

    expect($fluentResult->container)->toBeInstanceOf(ContainerValue::class)
        ->and($fluentResult->context)->toBe(['source' => 'fluent'])
        ->and($fluent->seenContext)->toBe(['source' => 'fluent'])
        ->and($bulkResult->container)->toBeInstanceOf(ContainerValue::class)
        ->and($bulkResult->context)->toBe(['source' => 'bulk'])
        ->and($bulk->seenContext)->toBe(['source' => 'bulk'])
        ->and($directResult->container)->toBeInstanceOf(ContainerValue::class)
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
    )->build())->toThrow(InvalidConfigurationException::class);

    expect(fn() => (new ContainerBuilder())->addDefinition(
        'hidden.method',
        ClassDefinition::create(DefinitionMethodTarget::class)->method('hidden'),
    )->build())->toThrow(InvalidConfigurationException::class);
});

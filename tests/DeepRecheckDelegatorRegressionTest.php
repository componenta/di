<?php

declare(strict_types=1);

require_once __DIR__ . '/Fixture/container_helpers.php';

use Componenta\Config\Config;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Tests\Fixture\DelegatorContract;
use Componenta\DI\Tests\Fixture\DelegatorContractImplementation;

test('builder accepts an interface instance method as a delegator reference', function () {
    $container = minimalBuilder()
        ->addInvokable(DelegatorContractImplementation::class)
        ->addAlias(DelegatorContract::class, DelegatorContractImplementation::class)
        ->addService('service', 'base')
        ->addDelegator('service', [DelegatorContract::class, 'decorate'])
        ->build();

    expect($container->get('service'))->toBe('base:decorated');
});

test('dependency configuration accepts an interface instance method as one delegator reference', function () {
    $container = ContainerBuilder::configureWithDependencies(
        new Config([]),
        [ConfigKey::DELEGATORS => ['service' => [DelegatorContract::class, 'decorate']]],
    )
        ->addInvokable(DelegatorContractImplementation::class)
        ->addAlias(DelegatorContract::class, DelegatorContractImplementation::class)
        ->addService('service', 'base')
        ->build();

    expect($container->get('service'))->toBe('base:decorated');
});

test('builder accepts an opaque service id method as a delegator reference', function () {
    $container = minimalBuilder()
        ->addService('decorator.service', new DelegatorContractImplementation())
        ->addService('service', 'base')
        ->addDelegator('service', ['decorator.service', 'decorate'])
        ->build();

    expect($container->get('service'))->toBe('base:decorated');
});

test('dependency configuration accepts a nested opaque service method delegator reference', function () {
    $container = ContainerBuilder::configureWithDependencies(
        new Config([]),
        [ConfigKey::DELEGATORS => [
            'service' => [['decorator.service', 'decorate']],
        ]],
    )
        ->addService('decorator.service', new DelegatorContractImplementation())
        ->addService('service', 'base')
        ->build();

    expect($container->get('service'))->toBe('base:decorated');
});

test('factory configuration rejects a private object method reference at the boundary', function () {
    $factory = new class () {
        private function hidden(): string
        {
            return 'should-not-run';
        }
    };

    expect(fn() => ContainerBuilder::configureWithDependencies(
        new Config([]),
        [ConfigKey::FACTORIES => ['service' => [$factory, 'hidden']]],
    ))->toThrow(InvalidConfigurationException::class);
});

<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\CallableInvokerInterface;
use Componenta\DI\CallableResolverInterface;
use Componenta\DI\Container;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\FactoryInterface;
use Componenta\DI\LazyObjectFactoryInterface;
use Componenta\DI\Object\ObjectPipeline;
use Componenta\DI\ProxyFactoryInterface;
use Componenta\DI\Resolver\Attribute\AttributeProcessor;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Componenta\DI\VirtualProxyFactoryInterface;
use Psr\Container\ContainerInterface;

test('core service ids cannot be replaced redirected or decorated at runtime', function (string $id): void {
    $container = (new ContainerBuilder())->build();
    $container->set('replacement', 'application');

    expect(fn() => $container->set($id, new \stdClass()))
        ->toThrow(InvalidConfigurationException::class, 'Cannot replace protected DI id')
        ->and(fn() => $container->alias($id, 'replacement'))
        ->toThrow(InvalidConfigurationException::class, 'Cannot replace protected DI alias')
        ->and(fn() => $container->delegator($id, static fn(object $entry): object => $entry))
        ->toThrow(InvalidConfigurationException::class, 'Cannot decorate protected DI id')
        ->and($container->get('replacement'))->toBe('application');
})->with([
    ConfigAttribute::KEY,
    Config::class,
    Environment::class,
    ContainerValue::class,
    DependencyDefinitions::class,
    Container::class,
    ContainerInterface::class,
    FactoryInterface::class,
    CallableInvokerInterface::class,
    CallableResolverInterface::class,
    CallableExecutorInterface::class,
    ProxyFactoryInterface::class,
    LazyObjectFactoryInterface::class,
    VirtualProxyFactoryInterface::class,
    AttributeDefinitionRegistry::class,
    AttributePlanBuilder::class,
    AttributeProcessor::class,
    ParametersResolver::class,
    ObjectPipeline::class,
]);

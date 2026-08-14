<?php

declare(strict_types=1);

use Componenta\DI\Cache\DiCacheGenerator;
use Componenta\DI\Cache\DiCacheGeneratorInterface;
use Componenta\DI\CallableExecutor;
use Componenta\DI\CallableInvoker;
use Componenta\DI\CallableInvokerInterface;
use Componenta\DI\CallableResolver;
use Componenta\DI\CallableResolverInterface;
use Componenta\DI\Compile\Definition\ClassDefinitionCodeGenerator;
use Componenta\DI\Compile\Definition\DefinitionCodeGeneratorInterface;
use Componenta\DI\Compile\Definition\DefinitionCompiler;
use Componenta\DI\Compile\Definition\DefinitionCompilerInterface;
use Componenta\DI\Container;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\FactoryInterface;
use Componenta\DI\LazyObjectFactoryInterface;
use Componenta\DI\ProxyFactory;
use Componenta\DI\Resolver\Parameter\Request\LazyFactory;
use Componenta\DI\VirtualProxyFactoryInterface;

it('keeps public named-argument contracts aligned with implementations', function (
    string $implementation,
    string $contract,
    string $method,
    array $expected,
) {
    $names = static fn(string $class): array => array_map(
        static fn(\ReflectionParameter $parameter): string => $parameter->getName(),
        (new \ReflectionMethod($class, $method))->getParameters(),
    );

    expect($names($contract))->toBe($expected)
        ->and($names($implementation))->toBe($expected);
})->with([
    'container make' => [Container::class, FactoryInterface::class, 'make', ['entry', 'params']],
    'container call' => [Container::class, CallableInvokerInterface::class, 'call', ['callable', 'params']],
    'container lazy object' => [
        Container::class,
        LazyObjectFactoryInterface::class,
        'makeLazy',
        ['class', 'initializer'],
    ],
    'container virtual proxy' => [
        Container::class,
        VirtualProxyFactoryInterface::class,
        'makeProxy',
        ['class', 'factory'],
    ],
    'proxy factory lazy object' => [
        ProxyFactory::class,
        LazyObjectFactoryInterface::class,
        'makeLazy',
        ['class', 'initializer'],
    ],
    'proxy factory virtual proxy' => [
        ProxyFactory::class,
        VirtualProxyFactoryInterface::class,
        'makeProxy',
        ['class', 'factory'],
    ],
    'callable executor call' => [
        CallableExecutor::class,
        CallableInvokerInterface::class,
        'call',
        ['callable', 'params'],
    ],
    'callable invoker call' => [
        CallableInvoker::class,
        CallableInvokerInterface::class,
        'call',
        ['callable', 'params'],
    ],
    'callable executor resolve' => [
        CallableExecutor::class,
        CallableResolverInterface::class,
        'resolve',
        ['callable'],
    ],
    'callable resolver resolve' => [
        CallableResolver::class,
        CallableResolverInterface::class,
        'resolve',
        ['callable'],
    ],
    'lazy request factory make' => [
        LazyFactory::class,
        FactoryInterface::class,
        'make',
        ['entry', 'params'],
    ],
    'cache generator' => [
        DiCacheGenerator::class,
        DiCacheGeneratorInterface::class,
        'generate',
        ['dependencies', 'path'],
    ],
    'definition code generator' => [
        ClassDefinitionCodeGenerator::class,
        DefinitionCodeGeneratorInterface::class,
        'generate',
        ['id', 'definition'],
    ],
    'definition compiler' => [
        DefinitionCompiler::class,
        DefinitionCompilerInterface::class,
        'compile',
        ['dependencies'],
    ],
    'builder configure from cache' => [
        ContainerBuilder::class,
        ContainerBuilder::class,
        'configureFromCache',
        ['config', 'cache', 'baseDir'],
    ],
    'builder compile factories' => [
        ContainerBuilder::class,
        ContainerBuilder::class,
        'compileFactories',
        ['entries', 'directory', 'generators', 'maxShardBytes', 'namespace'],
    ],
    'builder add parameter resolver' => [
        ContainerBuilder::class,
        ContainerBuilder::class,
        'addParameterResolver',
        ['resolver', 'priority'],
    ],
    'builder add attribute handler' => [
        ContainerBuilder::class,
        ContainerBuilder::class,
        'addAttributeHandler',
        ['handler'],
    ],
]);

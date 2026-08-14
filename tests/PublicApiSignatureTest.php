<?php

declare(strict_types=1);

namespace Componenta\DI\Tests;

use Componenta\DI\Attribute\Cast;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\Attribute\CurrentUser;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Attribute\Env;
use Componenta\DI\Attribute\Init;
use Componenta\DI\Attribute\Make;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Attribute\QueryParam;
use Componenta\DI\Attribute\RequestMapper;
use Componenta\DI\Attribute\SetUp;
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
use Psr\Container\ContainerInterface;

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
    'container get' => [Container::class, ContainerInterface::class, 'get', ['id']],
    'container has' => [Container::class, ContainerInterface::class, 'has', ['id']],
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

it('keeps concrete Container and ContainerBuilder named arguments stable', function (
    string $class,
    string $method,
    array $expected,
): void {
    $names = array_map(
        static fn(\ReflectionParameter $parameter): string => $parameter->getName(),
        (new \ReflectionMethod($class, $method))->getParameters(),
    );

    expect($names)->toBe($expected);
})->with([
    'container create' => [Container::class, 'create', ['config']],
    'container set' => [Container::class, 'set', ['id', 'entry']],
    'container alias' => [Container::class, 'alias', ['alias', 'target']],
    'container delegator' => [Container::class, 'delegator', ['id', 'delegator']],
    'container add external' => [Container::class, 'addContainer', ['container']],
    'builder configure' => [ContainerBuilder::class, 'configure', ['config']],
    'builder configure dependencies' => [
        ContainerBuilder::class,
        'configureWithDependencies',
        ['config', 'dependencies'],
    ],
    'builder normalize dependencies' => [
        ContainerBuilder::class,
        'normalizeDependencies',
        ['dependencies'],
    ],
    'builder add factory' => [ContainerBuilder::class, 'addFactory', ['id', 'factory']],
    'builder add factories' => [ContainerBuilder::class, 'addFactories', ['factories']],
    'builder add invokable' => [
        ContainerBuilder::class,
        'addInvokable',
        ['classOrAlias', 'class'],
    ],
    'builder add invokables' => [ContainerBuilder::class, 'addInvokables', ['invokables']],
    'builder add alias' => [ContainerBuilder::class, 'addAlias', ['alias', 'target']],
    'builder add aliases' => [ContainerBuilder::class, 'addAliases', ['aliases']],
    'builder add delegator' => [ContainerBuilder::class, 'addDelegator', ['id', 'delegator']],
    'builder add delegators' => [ContainerBuilder::class, 'addDelegators', ['delegators']],
    'builder add service' => [ContainerBuilder::class, 'addService', ['id', 'service']],
    'builder add services' => [ContainerBuilder::class, 'addServices', ['services']],
    'builder replace parameter resolvers' => [
        ContainerBuilder::class,
        'replaceParameterResolvers',
        ['replace'],
    ],
    'builder replace attribute handlers' => [
        ContainerBuilder::class,
        'replaceAttributeHandlers',
        ['replace'],
    ],
    'builder build' => [ContainerBuilder::class, 'build', []],
    'builder to array' => [ContainerBuilder::class, 'toArray', []],
]);

it('keeps documented attribute constructor named arguments stable', function (
    string $attribute,
    array $expected,
): void {
    $constructor = (new \ReflectionClass($attribute))->getConstructor();
    $names = $constructor === null
        ? []
        : array_map(
            static fn(\ReflectionParameter $parameter): string => $parameter->getName(),
            $constructor->getParameters(),
        );

    expect($names)->toBe($expected);
})->with([
    'EntryId' => [EntryId::class, ['value']],
    'Config' => [ConfigAttribute::class, ['path', 'default']],
    'Env' => [Env::class, ['name', 'default']],
    'Make' => [Make::class, ['entry', 'params']],
    'Init' => [Init::class, ['callable', 'params']],
    'Cast' => [Cast::class, ['name', 'default']],
    'CurrentUser' => [CurrentUser::class, ['type']],
    'SetUp' => [SetUp::class, ['method', 'params']],
    'Proxy' => [Proxy::class, ['class']],
    'QueryParam' => [QueryParam::class, ['name', 'default', 'cast']],
    'RequestMapper' => [RequestMapper::class, ['map', 'conflictPolicy']],
]);

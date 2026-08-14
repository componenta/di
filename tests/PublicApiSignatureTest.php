<?php

declare(strict_types=1);

namespace Componenta\DI\Tests;

use Componenta\DI\Attribute\Cast;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\Attribute\Cookie;
use Componenta\DI\Attribute\CurrentUser;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Attribute\Env;
use Componenta\DI\Attribute\Header;
use Componenta\DI\Attribute\Init;
use Componenta\DI\Attribute\Make;
use Componenta\DI\Attribute\MapCookies;
use Componenta\DI\Attribute\MapHeaders;
use Componenta\DI\Attribute\MapQueryString;
use Componenta\DI\Attribute\MapRequestAttributes;
use Componenta\DI\Attribute\MapRequestPayload;
use Componenta\DI\Attribute\MapServerParams;
use Componenta\DI\Attribute\MapUploadedFiles;
use Componenta\DI\Attribute\PayloadParam;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Attribute\QueryParam;
use Componenta\DI\Attribute\RequestAttribute;
use Componenta\DI\Attribute\RequestMapper;
use Componenta\DI\Attribute\ServerParam;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\Attribute\UploadedFile;
use Componenta\DI\Cache\DiCacheGenerator;
use Componenta\DI\Cache\DiCacheGeneratorInterface;
use Componenta\DI\CallableExecutor;
use Componenta\DI\CallableInvoker;
use Componenta\DI\CallableInvokerInterface;
use Componenta\DI\CallableResolver;
use Componenta\DI\CallableResolverInterface;
use Componenta\DI\Compile\Autowire\AutowireEntry;
use Componenta\DI\Compile\Definition\ClassDefinitionCodeGenerator;
use Componenta\DI\Compile\Definition\DefinitionCodeGeneratorInterface;
use Componenta\DI\Compile\Definition\DefinitionCodeGeneratorRegistry;
use Componenta\DI\Compile\Definition\DefinitionCompiler;
use Componenta\DI\Compile\Definition\DefinitionCompilerInterface;
use Componenta\DI\Compile\Factory\CompiledFactoryDefinition;
use Componenta\DI\Container;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\Definition;
use Componenta\DI\Definition\FactoryDefinition;
use Componenta\DI\Definition\InvokableDefinition;
use Componenta\DI\Definition\ReferenceDefinition;
use Componenta\DI\FactoryInterface;
use Componenta\DI\LazyObjectFactoryInterface;
use Componenta\DI\LazyServiceFactoryInterface;
use Componenta\DI\ProxyFactory;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Parameter\Request\LazyFactory;
use Componenta\DI\VirtualProxyFactoryInterface;
use Psr\Container\ContainerInterface;

/*
 * Explicit SemVer manifest for the named-argument surface documented in README.
 * Adding a documented public entry point should add its parameter names here.
 */

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
    'lazy service factory' => [
        LazyServiceFactoryInterface::class,
        LazyServiceFactoryInterface::class,
        'lazy',
        ['container', 'proxyFactory', 'context'],
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
    'parameter resolver supports' => [
        ParameterResolverInterface::class,
        ParameterResolverInterface::class,
        'supports',
        ['target'],
    ],
    'parameter resolver resolve' => [
        ParameterResolverInterface::class,
        ParameterResolverInterface::class,
        'resolveParameter',
        ['target', 'context'],
    ],
    'attribute handler supports' => [
        AttributeHandlerInterface::class,
        AttributeHandlerInterface::class,
        'supportsAttribute',
        ['attributeClass', 'target'],
    ],
    'attribute handler handle' => [
        AttributeHandlerInterface::class,
        AttributeHandlerInterface::class,
        'handle',
        ['attribute', 'target', 'context'],
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

it('keeps concrete public API named arguments stable', function (
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
    'definition factory' => [Definition::class, 'factory', ['factory']],
    'definition reference' => [Definition::class, 'reference', ['entryId']],
    'definition invokable' => [Definition::class, 'invokable', ['className']],
    'factory definition constructor' => [FactoryDefinition::class, '__construct', ['value']],
    'reference definition constructor' => [ReferenceDefinition::class, '__construct', ['value']],
    'invokable definition constructor' => [InvokableDefinition::class, '__construct', ['value']],
    'compiled factory definition constructor' => [
        CompiledFactoryDefinition::class,
        '__construct',
        ['file', 'class', 'method'],
    ],
    'autowire entry constructor' => [AutowireEntry::class, '__construct', ['class']],
    'definition generator registry register' => [
        DefinitionCodeGeneratorRegistry::class,
        'register',
        ['definitionClass', 'generator'],
    ],
    'definition generator registry find' => [
        DefinitionCodeGeneratorRegistry::class,
        'find',
        ['definition'],
    ],
    'class definition constructor' => [
        ClassDefinition::class,
        '__construct',
        ['value', 'constructorParams', 'methodCalls'],
    ],
    'class definition create' => [ClassDefinition::class, 'create', ['className']],
    'class definition parameters' => [ClassDefinition::class, 'constructor', ['params']],
    'class definition method' => [ClassDefinition::class, 'method', ['method', 'params']],
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
    'PayloadParam' => [PayloadParam::class, ['name', 'default', 'cast']],
    'Header' => [Header::class, ['name', 'default', 'cast']],
    'Cookie' => [Cookie::class, ['name', 'default', 'cast']],
    'RequestAttribute' => [RequestAttribute::class, ['name', 'default', 'cast']],
    'ServerParam' => [ServerParam::class, ['name', 'default', 'cast']],
    'UploadedFile' => [UploadedFile::class, ['name']],
    'RequestMapper' => [RequestMapper::class, ['map', 'conflictPolicy']],
    'MapQueryString' => [MapQueryString::class, ['map', 'conflictPolicy']],
    'MapRequestPayload' => [MapRequestPayload::class, ['map', 'conflictPolicy']],
    'MapHeaders' => [MapHeaders::class, ['map', 'conflictPolicy']],
    'MapCookies' => [MapCookies::class, ['map', 'conflictPolicy']],
    'MapRequestAttributes' => [MapRequestAttributes::class, ['map', 'conflictPolicy']],
    'MapServerParams' => [MapServerParams::class, ['map', 'conflictPolicy']],
    'MapUploadedFiles' => [MapUploadedFiles::class, ['map', 'conflictPolicy']],
]);

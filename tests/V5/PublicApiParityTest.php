<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

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
use Componenta\DI\CallableExecutor;
use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\CallableInvokerInterface;
use Componenta\DI\CallableResolverInterface;
use Componenta\DI\ConfigProvider;
use Componenta\DI\Container;
use Componenta\DI\FactoryInterface;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;

function publicParameterNames(string $class, string $method): array
{
    return array_map(
        static fn(\ReflectionParameter $parameter): string => $parameter->getName(),
        (new \ReflectionMethod($class, $method))->getParameters(),
    );
}

test('v5 preserves the v4 array-based public resolution contracts', function (): void {
    expect(publicParameterNames(FactoryInterface::class, 'make'))->toBe(['entry', 'params'])
        ->and(publicParameterNames(Container::class, 'make'))->toBe(['entry', 'params'])
        ->and(publicParameterNames(CallableInvokerInterface::class, 'call'))->toBe(['callable', 'params'])
        ->and(publicParameterNames(CallableExecutor::class, 'call'))->toBe(['callable', 'params'])
        ->and(publicParameterNames(CallableResolverInterface::class, 'resolve'))->toBe(['callable'])
        ->and(publicParameterNames(ParameterResolverInterface::class, 'supports'))->toBe(['target'])
        ->and(publicParameterNames(ParameterResolverInterface::class, 'resolveParameter'))->toBe(['target', 'context'])
        ->and(publicParameterNames(AttributeHandlerInterface::class, 'handle'))
        ->toBe(['attribute', 'target', 'context'])
        ->and((new \ReflectionClass(CallableExecutorInterface::class))->hasMethod('execute'))
        ->toBeFalse();
});

test('v5 preserves v4 attribute constructor named arguments', function (
    string $attribute,
    array $expected,
): void {
    $constructor = (new \ReflectionClass($attribute))->getConstructor();
    $actual = $constructor === null
        ? []
        : array_map(
            static fn(\ReflectionParameter $parameter): string => $parameter->getName(),
            $constructor->getParameters(),
        );

    expect($actual)->toBe($expected);
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

test('Componenta package config provider remains discoverable and callable', function (): void {
    $provider = new ConfigProvider();

    expect(is_callable($provider))->toBeTrue()
        ->and($provider())->toBeArray();
});

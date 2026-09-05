<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\DI\Exception\InvalidCallableException;
use Componenta\DI\Tests\Support\ContainerBuilder;

final readonly class CallableResolutionDependency
{
    public function __construct(public string $value = 'dependency') {}
}

interface CallableResolutionContract
{
    public function run(): string;
}

final readonly class CallableResolutionService
{
    public function instance(CallableResolutionDependency $dependency, string $suffix = '!'): string
    {
        return $dependency->value . $suffix;
    }

    public static function staticMethod(string $value): string
    {
        return strtoupper($value);
    }

    protected static function hiddenStatic(): string
    {
        return 'hidden';
    }

    protected function hiddenInstance(): string
    {
        return 'hidden';
    }
}

final readonly class CallableResolutionInvokable
{
    public function __invoke(CallableResolutionDependency $dependency): string
    {
        return $dependency->value;
    }
}

function callableResolutionFunction(string $value): string
{
    return 'function:' . $value;
}

test('public call resolves every documented callable form with DI-aware parameters', function (): void {
    $service = new CallableResolutionService();
    $container = (new ContainerBuilder())
        ->addService('opaque.callable', static fn(): string => 'opaque')
        ->addService('method.service', $service)
        ->build();

    expect($container->call('opaque.callable'))->toBe('opaque')
        ->and($container->call(__NAMESPACE__ . '\\callableResolutionFunction', [
            'value' => 'named',
        ]))->toBe('function:named')
        ->and($container->call(CallableResolutionService::class . '::staticMethod', [
            'value' => 'static',
        ]))->toBe('STATIC')
        ->and($container->call(CallableResolutionService::class . '::instance', [
            'suffix' => ':class',
        ]))->toBe('dependency:class')
        ->and($container->call(['method.service', 'instance'], [
            'suffix' => ':service',
        ]))->toBe('dependency:service')
        ->and($container->call([$service, 'instance'], [
            'suffix' => ':object',
        ]))->toBe('dependency:object')
        ->and($container->call(new CallableResolutionInvokable()))->toBe('dependency');
});

test('public resolve preserves native callable identity and opaque service-id precedence', function (): void {
    $closure = static fn(): string => 'closure';
    $serviceCallable = static fn(): string => 'service';
    $container = (new ContainerBuilder())
        ->addService('strlen', $serviceCallable)
        ->build();

    expect($container->resolve($closure))->toBe($closure)
        ->and($container->resolve('strlen'))->toBe($serviceCallable)
        ->and(($container->resolve([CallableResolutionService::class, 'staticMethod']))('value'))
        ->toBe('VALUE');
});

test('public resolve reports invalid callable specifications precisely', function (
    mixed $specification,
    string $message,
): void {
    $container = (new ContainerBuilder())
        ->addService('not.callable', new \stdClass())
        ->build();

    expect(fn() => $container->resolve($specification))
        ->toThrow(InvalidCallableException::class, $message);
})->with([
    'non-callable service' => ['not.callable', 'is not invokable'],
    'non-invokable class service' => [CallableResolutionService::class, 'is not invokable'],
    'missing class method' => [CallableResolutionService::class . '::missing', 'does not exist'],
    'private static method' => [CallableResolutionService::class . '::hiddenStatic', 'does not exist'],
    'private instance method' => [CallableResolutionService::class . '::hiddenInstance', 'does not exist'],
    'missing interface service' => [CallableResolutionContract::class, 'is not defined'],
    'missing interface method owner' => [CallableResolutionContract::class . '::run', 'is not defined'],
    'unknown class method owner' => ['Missing\\CallableResolutionOwner::method', 'Cannot convert'],
    'missing object method' => [[new CallableResolutionService(), 'missing'], 'does not exist'],
    'empty array method' => [[CallableResolutionService::class, ''], 'Cannot convert'],
    'invalid array owner' => [[42, 'method'], 'Cannot convert'],
    'invalid array shape' => [[CallableResolutionService::class], 'Cannot convert'],
    'associative array' => [['owner' => CallableResolutionService::class, 'method' => 'instance'], 'Cannot convert'],
    'shifted array keys' => [[1 => CallableResolutionService::class, 2 => 'instance'], 'Cannot convert'],
    'scalar value' => [42, 'Cannot convert'],
]);

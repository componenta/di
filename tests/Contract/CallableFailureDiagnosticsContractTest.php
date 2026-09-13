<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\DI\CallableResolver;
use Componenta\DI\Exception\InvalidCallableException;
use Psr\Container\ContainerInterface;

final class CallableWithoutService
{
    private function __construct() {}

    public function instance(): string
    {
        return self::hidden();
    }

    private static function hidden(): string
    {
        return 'hidden';
    }
}

final class OtherCallableWithoutService
{
    public function hidden(): string
    {
        return 'instance';
    }
}

final class EmptyCallableContainer implements ContainerInterface
{
    public function has(string $id): bool
    {
        return false;
    }

    public function get(string $id): mixed
    {
        throw new \LogicException('No service was advertised: ' . $id);
    }
}

test('unresolvable methods distinguish a missing service from a missing or inaccessible method', function (
    mixed $specification,
    string $message,
    string $description,
): void {
    $resolver = new CallableResolver(new EmptyCallableContainer());

    try {
        $resolver->resolve($specification);
        \PHPUnit\Framework\Assert::fail('The unavailable callable must be rejected.');
    } catch (InvalidCallableException $exception) {
        expect($exception->getMessage())->toBe($message)
            ->and($exception->callableDescription)->toBe($description)
            ->and($exception->getPrevious())->toBeNull();
    }
})->with([
    'unregistered instance method' => [
        [CallableWithoutService::class, 'instance'],
        'Service "' . CallableWithoutService::class . '" is not defined in the container.',
        CallableWithoutService::class,
    ],
    'hidden static method' => [
        [CallableWithoutService::class, 'hidden'],
        'Method "' . CallableWithoutService::class . '::hidden()" does not exist.',
        CallableWithoutService::class . '::hidden',
    ],
    'missing method' => [
        [CallableWithoutService::class, 'missing'],
        'Method "' . CallableWithoutService::class . '::missing()" does not exist.',
        CallableWithoutService::class . '::missing',
    ],
    'unknown owner' => [
        ['unknown.callable.service', 'run'],
        'Cannot convert value of type "array" to a callable.',
        'unknown.callable.service::run',
    ],
    'empty owner' => [
        ['', 'run'],
        'Cannot convert value of type "array" to a callable.',
        '::run',
    ],
    'sparse pair' => [
        [0 => 'owner', 2 => 'run'],
        'Cannot convert value of type "array" to a callable.',
        'array',
    ],
    'integer owner' => [
        [42, 'run'],
        'Cannot convert value of type "array" to a callable.',
        'int::run',
    ],
]);

test('method classification remains specific to both declaring class and method across repeated failures', function (): void {
    $resolver = new CallableResolver(new EmptyCallableContainer());

    foreach ([1, 2] as $_attempt) {
        expect(fn() => $resolver->resolve([CallableWithoutService::class, 'hidden']))
            ->toThrow(InvalidCallableException::class, 'Method "' . CallableWithoutService::class . '::hidden()" does not exist.')
            ->and(fn() => $resolver->resolve([CallableWithoutService::class, 'instance']))
            ->toThrow(InvalidCallableException::class, 'Service "' . CallableWithoutService::class . '" is not defined in the container.')
            ->and(fn() => $resolver->resolve([OtherCallableWithoutService::class, 'hidden']))
            ->toThrow(InvalidCallableException::class, 'Service "' . OtherCallableWithoutService::class . '" is not defined in the container.');
    }
});

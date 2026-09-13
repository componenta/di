<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\MagicCallableSyntax;

use Componenta\DI\Exception\InvalidCallableException;
use Componenta\DI\Tests\Support\ContainerBuilder;

final class MagicService
{
    protected static function hidden(string $irrelevant): never
    {
        throw new \LogicException($irrelevant);
    }

    /**
     * @param array<array-key,mixed> $arguments
     * @return array{string,array<array-key,mixed>}
     */
    public function __call(string $method, array $arguments): array
    {
        return [$method, $arguments];
    }
}
final class StaticMagicService
{
    /**
     * @param array<array-key,mixed> $arguments
     * @return array{string,array<array-key,mixed>}
     */
    public static function __callStatic(string $method, array $arguments): array
    {
        return [$method, $arguments];
    }
}
final class OrdinaryService
{
    public function run(string $value): string
    {
        return $value;
    }
}

test('string and array callable syntax preserve magic arguments', function (string $class): void {
    $di = (new ContainerBuilder())->build();
    $arguments = ['one', 'named' => 'two'];

    expect($di->call($class . '::dynamic', $arguments))->toBe(['dynamic', $arguments])
        ->and($di->call([$class, 'dynamic'], $arguments))->toBe(['dynamic', $arguments]);
})->with([MagicService::class, StaticMagicService::class]);

test('instance magic dispatch handles inaccessible static methods in either syntax', function (): void {
    $di = (new ContainerBuilder())->build();
    $arguments = ['one', 'named' => 'two'];

    expect($di->call([MagicService::class, 'hidden'], $arguments))->toBe(['hidden', $arguments])
        ->and($di->call(MagicService::class . '::hidden', $arguments))->toBe(['hidden', $arguments]);
});

test('exact callable service ids retain precedence over magic syntax', function (): void {
    $di = (new ContainerBuilder())
        ->addService(MagicService::class . '::dynamic', static fn(): string => 'registered')->build();

    expect($di->call(MagicService::class . '::dynamic'))->toBe('registered');
});

test('missing ordinary methods and empty method names stay invalid', function (string $specification): void {
    $di = (new ContainerBuilder())->build();
    expect(fn() => $di->call($specification))->toThrow(InvalidCallableException::class);
})->with([OrdinaryService::class . '::missing', MagicService::class . '::']);

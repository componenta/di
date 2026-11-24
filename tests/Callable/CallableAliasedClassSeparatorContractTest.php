<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Callable\CallableAliasedClassSeparatorContract;

use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;

final class Dependency {}

final class Receiver
{
    public static int $calls = 0;

    /** @return array{Dependency,string} */
    public static function consume(Dependency $dependency, string $label = 'default'): array
    {
        ++self::$calls;
        return [$dependency, $label];
    }

    /**
     * @param array<array-key,mixed> $arguments
     * @return array{string,array<array-key,mixed>}
     */
    public static function __callStatic(string $method, array $arguments): array
    {
        return [$method, $arguments];
    }
}

class_alias(Receiver::class, Receiver::class . '::Alias');

test('native class aliases containing method separators retain callable parameter resolution', function (string $owner, bool $array): void {
    $dependency = new Dependency();
    $container = (new ContainerBuilder())->addService(Dependency::class, $dependency)->build();
    $callable = $array ? [$owner, 'consume'] : $owner . '::consume';
    if (!is_callable($callable)) {
        throw new LogicException('The alias must be a native callable.');
    }
    Receiver::$calls = 0;

    expect($callable($dependency, 'native'))->toBe([$dependency, 'native'])
        ->and($container->call($callable, ['label' => 'first', 'unused' => 'ignored']))->toBe([$dependency, 'first'])
        ->and($container->call($callable, ['label' => 'second']))->toBe([$dependency, 'second'])
        ->and(Receiver::$calls)->toBe(3);
})->with(['ordinary class' => Receiver::class, 'separator alias' => Receiver::class . '::Alias'])
    ->with(['method string' => false, 'method array' => true]);

test('separator aliases preserve native magic argument binding', function (bool $array): void {
    $owner = Receiver::class . '::Alias';
    $callable = $array ? [$owner, 'dynamic'] : $owner . '::dynamic';
    if (!is_callable($callable)) {
        throw new LogicException('The alias must retain native magic dispatch.');
    }
    $arguments = ['first', 'named' => 'second'];
    $expected = $callable(...$arguments);
    $container = (new ContainerBuilder())->build();

    expect($expected)->toBe(['dynamic', $arguments])
        ->and($container->call($callable, $arguments))->toBe($expected)
        ->and($container->call($callable, $arguments))->toBe($expected);
})->with(['method string' => false, 'method array' => true]);

test('exact callable service ids still precede separator alias method syntax', function (): void {
    $id = Receiver::class . '::Alias::consume';
    $container = (new ContainerBuilder())->addService($id, static fn(): string => 'registered')->build();

    expect($container->call($id))->toBe('registered');
});

<?php

declare(strict_types=1);

namespace Componenta\DI\Tests;

use Componenta\DI\ContainerBuilder;
use Componenta\Reflection\Reflection;

final class CallableCacheDependency {}

final class AlternateCallableCacheDependency {}

final class StaticCallableCacheTarget
{
    public static function length(string $value): int
    {
        return strlen($value);
    }
}

it('does not conflate different closure parameter signatures', function () {
    $container = (new ContainerBuilder())->build();

    expect($container->call(static fn(CallableCacheDependency $dependency): object => $dependency))
        ->toBeInstanceOf(CallableCacheDependency::class)
        ->and($container->call(static fn(AlternateCallableCacheDependency $dependency): object => $dependency))
        ->toBeInstanceOf(AlternateCallableCacheDependency::class);
});

it('does not let callable reflection poison repeated container lookups', function (): void {
    Reflection::clearReflectors();
    $container = (new ContainerBuilder())->build();
    $staticCallable = StaticCallableCacheTarget::class . '::length';

    expect($container->call('strlen', ['string' => 'abc']))->toBe(3)
        ->and($container->call('strlen', ['string' => 'abcd']))->toBe(4)
        ->and($container->call($staticCallable, ['value' => 'abcde']))->toBe(5)
        ->and($container->call($staticCallable, ['value' => 'abcdef']))->toBe(6)
        ->and(Reflection::class($staticCallable))->toBeNull()
        ->and($container->has($staticCallable))->toBeFalse();

    $class = Reflection::class(StaticCallableCacheTarget::class);

    expect($class)->not->toBeNull()
        ->and($class?->getName())->toBe(StaticCallableCacheTarget::class);
});

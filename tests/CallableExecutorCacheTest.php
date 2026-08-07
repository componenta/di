<?php

declare(strict_types=1);

namespace Componenta\DI\Tests;

use Closure;
use Componenta\DI\ContainerBuilder;

final class CallableCacheDependency {}

final class AlternateCallableCacheDependency {}

it('reuses parameter targets across fresh closures from the same source signature', function () {
    $container = (new ContainerBuilder())->build();
    $factory = static fn(): Closure => static fn(
        CallableCacheDependency $dependency,
    ): CallableCacheDependency => $dependency;

    expect($container->call($factory()))
        ->toBeInstanceOf(CallableCacheDependency::class)
        ->and($container->call($factory()))
        ->toBeInstanceOf(CallableCacheDependency::class);
});

it('does not conflate different closure parameter signatures', function () {
    $container = (new ContainerBuilder())->build();

    expect($container->call(static fn(CallableCacheDependency $dependency): object => $dependency))
        ->toBeInstanceOf(CallableCacheDependency::class)
        ->and($container->call(static fn(AlternateCallableCacheDependency $dependency): object => $dependency))
        ->toBeInstanceOf(AlternateCallableCacheDependency::class);
});

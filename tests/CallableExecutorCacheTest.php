<?php

declare(strict_types=1);

namespace Componenta\DI\Tests;

use Componenta\DI\ContainerBuilder;

final class CallableCacheDependency {}

final class AlternateCallableCacheDependency {}

it('does not conflate different closure parameter signatures', function () {
    $container = (new ContainerBuilder())->build();

    expect($container->call(static fn(CallableCacheDependency $dependency): object => $dependency))
        ->toBeInstanceOf(CallableCacheDependency::class)
        ->and($container->call(static fn(AlternateCallableCacheDependency $dependency): object => $dependency))
        ->toBeInstanceOf(AlternateCallableCacheDependency::class);
});

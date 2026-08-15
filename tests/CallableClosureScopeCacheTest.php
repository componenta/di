<?php

declare(strict_types=1);

use Componenta\DI\ContainerBuilder;

trait CreatesScopedCallableForCacheTest
{
    public function scopedCallable(): \Closure
    {
        return static fn(self $dependency): object => $dependency;
    }
}

final class FirstScopedCallableOwner
{
    use CreatesScopedCallableForCacheTest;
}

final class SecondScopedCallableOwner
{
    use CreatesScopedCallableForCacheTest;
}

it('keeps closure parameter metadata isolated by lexical class scope', function (): void {
    $container = (new ContainerBuilder())->build();

    $first = $container->call((new FirstScopedCallableOwner())->scopedCallable());
    $second = $container->call((new SecondScopedCallableOwner())->scopedCallable());

    expect($first)->toBeInstanceOf(FirstScopedCallableOwner::class)
        ->and($second)->toBeInstanceOf(SecondScopedCallableOwner::class);
});

<?php

declare(strict_types=1);

use Componenta\DI\Attribute\Lazy;
use Componenta\DI\ProxyFactory;
use Componenta\DI\Resolver\Entry\InvokableResolver;

#[Lazy]
final class PrivateLazyInvokableConstructor
{
    public bool $initialized;

    private function __construct()
    {
        $this->initialized = true;
    }
}

it('initializes a private no-argument constructor through reflection', function (): void {
    $resolver = new InvokableResolver(
        [PrivateLazyInvokableConstructor::class],
        new ProxyFactory(),
    );

    $entry = $resolver->resolve(PrivateLazyInvokableConstructor::class);

    expect($entry->initialized)->toBeTrue();
});

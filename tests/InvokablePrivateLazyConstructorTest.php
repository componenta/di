<?php

declare(strict_types=1);

use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Entry\InvokableResolver;

#[Lazy]
final class PrivateLazyInvokableConstructor
{
    private function __construct() {}
}

it('does not bypass private constructors for explicit invokables', function (): void {
    $resolver = new InvokableResolver([PrivateLazyInvokableConstructor::class]);

    expect(fn() => $resolver->resolve(PrivateLazyInvokableConstructor::class))
        ->toThrow(ResolutionException::class);
});

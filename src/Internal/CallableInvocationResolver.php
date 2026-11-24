<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Closure;
use Componenta\DI\CallableResolver;
use LogicException;

/** Invokes the built-in resolver's invocation path without replacing public resolution. @internal */
final class CallableInvocationResolver
{
    /**
     * @param string|array<array-key,mixed> $callable
     * @return array{callable,array<array-key,mixed>|null}
     */
    public static function resolve(CallableResolver $resolver, string|array $callable): array
    {
        /** @var (Closure(CallableResolver,string|array<array-key,mixed>):array{callable,array<array-key,mixed>|null})|null $resolve */
        static $resolve = null;
        $resolve ??= Closure::bind(
            static function (CallableResolver $resolver, string|array $callable): array {
                // The bound closure runs in the built-in resolver's class scope.
                return $resolver->resolveForInvocation($callable);
            },
            null,
            CallableResolver::class,
        ) ?? throw new LogicException('Could not bind the built-in callable resolver.');

        return $resolve($resolver, $callable);
    }
}

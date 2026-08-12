<?php

declare(strict_types=1);

namespace Componenta\DI;

/** Resolves and executes callables with dependency injection. */
interface CallableExecutorInterface extends CallableInvokerInterface, CallableResolverInterface
{
    /**
     * Executes a callable using explicit invocation parameters plus ambient DI
     * resolution context.
     *
     * The `$params` name is intentionally retained from CallableInvokerInterface
     * because PHP named arguments make interface parameter names part of the
     * practical public contract.
     *
     * @param array<string|int, mixed> $params Explicit callable arguments.
     * @param array<string|int, mixed> $context Ambient values available to DI resolvers.
     */
    public function call(
        mixed $callable,
        array $params = [],
        array $context = [],
    ): mixed;
}

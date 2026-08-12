<?php

declare(strict_types=1);

namespace Componenta\DI;

/** Resolves and executes callables with dependency injection. */
interface CallableExecutorInterface extends CallableInvokerInterface, CallableResolverInterface
{
    /**
     * Executes a callable using explicit invocation arguments plus ambient DI
     * resolution context.
     *
     * @param array<string|int, mixed> $arguments
     * @param array<string|int, mixed> $context
     */
    public function call(
        mixed $callable,
        array $arguments = [],
        array $context = [],
    ): mixed;
}

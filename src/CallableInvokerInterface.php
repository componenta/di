<?php

declare(strict_types=1);

namespace Componenta\DI;

use Componenta\DI\Exception\ExceptionInterface;

/**
 * Invokes a callable with a caller-supplied parameter list.
 *
 * A PreparedCallable marks a call with already resolved native arguments. Executor
 * decorators preserve that mode by forwarding the
 * adapter and parameter list intact.
 *
 * DI-aware implementations normalize failures that happen while resolving the
 * callable or its arguments to {@see ExceptionInterface}. Once control enters
 * the target callable body, throwables raised by that callable propagate
 * unchanged.
 */
interface CallableInvokerInterface
{
    /**
     * @param mixed $callable
     * @param array<string|int, mixed> $params
     * @return mixed
     * @throws ExceptionInterface If callable preparation or DI argument resolution fails.
     * @throws \Throwable Anything thrown by the target callable after invocation begins.
     */
    public function call(mixed $callable, array $params = []): mixed;
}

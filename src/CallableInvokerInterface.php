<?php

declare(strict_types=1);

namespace Componenta\DI;

use Componenta\DI\Exception\CallableExceptionInterface;
use Componenta\DI\Exception\ExceptionInterface;

/**
 * Invokes a callable with a caller-supplied parameter list.
 *
 * Whether parameters are autowired or used as-is is implementation-defined:
 *
 *  - {@see CallableExecutor} (the {@see Container}-backed executor) resolves
 *    each parameter through the DI parameter chain, with `$params` providing
 *    overrides by name or position.
 *  - {@see CallableInvoker} is a thin wrapper that calls
 *    {@see call_user_func_array()} verbatim - no resolution, no DI. Useful
 *    for hot paths or non-DI callers (CQRS handlers, tests).
 *
 * Failures to resolve or normalize a callable surface as DI-container
 * exceptions. Once target invocation begins, throwables raised by PHP or by
 * the target callable propagate unchanged so callers keep the original error
 * type and stack trace.
 */
interface CallableInvokerInterface
{
    /**
     * Invokes a callable with the given parameters.
     *
     * @param mixed $callable The callable to invoke.
     * @param array<string|int, mixed> $params Parameters passed to the callable.
     *                                         Resolution semantics depend on
     *                                         the implementation (see class docblock).
     *
     * @return mixed The result of the callable execution.
     *
     * @throws CallableExceptionInterface If the callable cannot be resolved or normalized.
     * @throws ExceptionInterface         If a parameter cannot be resolved (DI implementations only).
     */
    public function call(mixed $callable, array $params = []): mixed;
}

<?php

declare(strict_types=1);

namespace Componenta\DI;

use Componenta\DI\Exception\InvalidCallableException;

/**
 * Thin callable-invocation wrapper.
 *
 * Minimal implementation over {@see call_user_func_array()} - performs no
 * resolution, validation, or dependency injection. Callers are expected to
 * hand in an already-valid callable (typically obtained from
 * {@see CallableResolver}) along with the complete, ordered parameter list.
 *
 * Exception policy (matches {@see CallableInvokerInterface}):
 *
 *  - Exceptions raised inside the callable itself propagate unchanged so the
 *    caller can handle domain errors directly.
 *  - PHP engine errors ({@see \Error} hierarchy - `TypeError`,
 *    `ArgumentCountError`, etc.) are translated into
 *    {@see InvalidCallableException} so the caller learns which callable and
 *    parameter set triggered the failure.
 *
 * @example Basic usage
 * ```php
 * $invoker = new CallableInvoker();
 * $result = $invoker->call(fn(int $a, int $b) => $a + $b, [2, 3]);
 * $result = $invoker->call([$controller, 'action'], [$request, $response]);
 * ```
 */
final class CallableInvoker implements CallableInvokerInterface
{
    /**
     * Invokes a callable with the given parameters.
     *
     * @param mixed $callable A valid callable ready for invocation.
     * @param array<int|string, mixed> $params Complete, ordered parameter list.
     *
     * @throws InvalidCallableException If the PHP engine reports an invocation
     *                                  failure (invalid callable, missing /
     *                                  mistyped arguments).
     */
    public function call(mixed $callable, array $params = []): mixed
    {
        try {
            return call_user_func_array($callable, $params);
        } catch (\Error $e) {
            // Engine-level invocation failure; user-level exceptions (domain
            // errors thrown by the callable body) propagate past this catch
            // unchanged per the interface contract.
            throw InvalidCallableException::forInvocation($callable, $params, $e);
        }
    }
}

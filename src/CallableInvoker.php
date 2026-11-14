<?php

declare(strict_types=1);

namespace Componenta\DI;

use Componenta\DI\Exception\ExceptionInterface;
use Componenta\DI\Exception\InvalidCallableException;
use Throwable;

/**
 * Thin callable-invocation wrapper.
 *
 * Minimal implementation over {@see call_user_func_array()} - performs no
 * dependency injection. Callers normally hand in a callable obtained from
 * {@see CallableResolver} along with the complete, ordered parameter list.
 *
 * Invalid callable values are normalized to {@see InvalidCallableException}.
 * Once invocation begins, throwables raised by PHP or by the target callable
 * propagate unchanged. The invoker cannot reliably distinguish an argument
 * binding TypeError from a TypeError raised inside the callable body without
 * reimplementing PHP's invocation semantics.
 */
final class CallableInvoker implements CallableInvokerInterface
{
    /**
     * Invokes a callable with the given parameters.
     *
     * @param mixed $callable A valid callable ready for invocation.
     * @param array<int|string, mixed> $params Complete, ordered parameter list.
     *
     * @throws InvalidCallableException If the value is not callable.
     */
    public function call(mixed $callable, array $params = []): mixed
    {
        try {
            if (!is_callable($callable)) {
                throw InvalidCallableException::forValue($callable);
            }
        } catch (ExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw InvalidCallableException::forValue($callable, $e);
        }

        return call_user_func_array($callable, $params);
    }
}

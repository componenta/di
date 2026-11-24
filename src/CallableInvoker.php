<?php

declare(strict_types=1);

namespace Componenta\DI;

use Closure;
use Componenta\DI\Exception\ExceptionInterface;
use Componenta\DI\Exception\InvalidCallableException;
use Componenta\DI\Internal\CallableMagicInvocation;
use ReflectionReference;
use Throwable;
use TypeError;

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
     * @throws ExceptionInterface If callable validation fails.
     * @throws Throwable Anything thrown by the target after invocation begins.
     */
    public function call(mixed $callable, array $params = []): mixed
    {
        if ($callable instanceof Closure) {
            return call_user_func_array($callable, $params);
        }

        // Shape validation does not resolve names or invoke autoloaders.
        if (!is_callable($callable, syntax_only: true)) {
            throw InvalidCallableException::forValue($callable);
        }

        try {
            // Capture once: validation can change referenced callable slots.
            // Keep the native call immediately after its recorded source line.
            $captureLine = __LINE__ + 1;
            $resolved = Closure::fromCallable($callable);
        } catch (ExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            $origin = $e->getTrace()[0] ?? [];
            $nativeFailure = $e instanceof TypeError
                && $e->getFile() === __FILE__
                && $e->getLine() === $captureLine
                && ($origin['class'] ?? null) === Closure::class
                && ($origin['function'] ?? null) === 'fromCallable'
                && ($origin['file'] ?? null) === __FILE__
                && ($origin['line'] ?? null) === $captureLine;
            // PHP's conversion error wraps an existing autoload/diagnostic error.
            // Remove only this call's native layer, preserving any original cause.
            $cause = $nativeFailure ? $e->getPrevious() : $e;
            if ($cause instanceof ExceptionInterface) {
                throw $cause;
            }
            throw InvalidCallableException::forValue($callable, $cause);
        }

        if ($params !== [] && (is_array($callable) || (is_string($callable) && str_contains($callable, '::')))) {
            $prepareMagic = !array_is_list($params);
            if (!$prepareMagic) {
                foreach ($params as $position => $_) {
                    if (ReflectionReference::fromArrayElement($params, $position) !== null) {
                        $prepareMagic = true;
                        break;
                    }
                }
            }
            if ($prepareMagic && ($magic = CallableMagicInvocation::prepare($resolved, $params)) !== null) {
                return call_user_func_array($magic[0], $magic[1]);
            }
        }

        return call_user_func_array($resolved, $params);
    }
}

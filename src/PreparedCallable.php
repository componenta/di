<?php

declare(strict_types=1);

namespace Componenta\DI;

/**
 * A native callable whose arguments have already been resolved.
 *
 * Pass this adapter to CallableExecutorInterface::call() with a native argument
 * list. DI must not inject or transform those arguments again. Executor
 * decorators can forward the adapter normally; intercepting executors can
 * inspect the original callable without losing its attributes.
 *
 * Arguments cross this adapter by value. For by-reference parameters, pass the
 * original callable directly to CallableInvoker with a reference-bearing array.
 */
final readonly class PreparedCallable
{
    /** @var callable */
    public mixed $callable;

    /** @param callable $callable */
    public function __construct(callable|array $callable)
    {
        // Native callable parameter validation can autoload before this body.
        // Accept arrays separately so their slots are captured before validation.
        $this->callable = is_array($callable) ? self::validateCallable(self::snapshotCallable($callable)) : $callable;
    }

    public function __invoke(mixed ...$arguments): mixed
    {
        return call_user_func_array($this->callable, $arguments);
    }

    /**
     * @template T of array<array-key,mixed>
     * @param T $callable
     * @return T
     */
    private static function snapshotCallable(array $callable): array
    {
        // Retain the validated route even when the caller later changes an
        // array's reference slots. The callable object itself keeps its identity.
        $snapshot = [];
        foreach ($callable as $key => $value) {
            $snapshot[$key] = $value;
        }

        /** @var T $snapshot */
        return $snapshot;
    }

    /** @return callable */
    private static function validateCallable(callable $callable): mixed
    {
        // Native argument validation preserves autoload exceptions. A callable
        // return check would wrap them and repeat callable diagnostics.
        return $callable;
    }
}

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
 */
final readonly class PreparedCallable
{
    /** @var callable */
    public mixed $callable;

    public function __construct(callable $callable)
    {
        $this->callable = $callable;
    }

    public function __invoke(mixed ...$arguments): mixed
    {
        return call_user_func_array($this->callable, $arguments);
    }
}

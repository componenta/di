<?php

declare(strict_types=1);

namespace Componenta\DI;

/**
 * Resolves and executes callables with dependency injection.
 */
interface CallableExecutorInterface extends CallableInvokerInterface, CallableResolverInterface
{
}
<?php

declare(strict_types=1);

namespace Componenta\DI;

use Componenta\DI\Exception\CallableExceptionInterface;

/**
 * Resolves various representations into valid PHP callables.
 */
interface CallableResolverInterface
{
    /**
     * Resolves the given input into a valid PHP callable.
     *
     * @throws CallableExceptionInterface If resolution fails.
     */
    public function resolve(mixed $callable): callable;
}

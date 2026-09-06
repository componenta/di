<?php

declare(strict_types=1);

namespace Componenta\DI;

use Componenta\DI\Exception\ExceptionInterface;

/** Resolves various representations into valid PHP callables. */
interface CallableResolverInterface
{
    /** @throws ExceptionInterface If the callable cannot be normalized or a backing service cannot be resolved. */
    public function resolve(mixed $callable): callable;
}

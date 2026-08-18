<?php

declare(strict_types=1);

namespace Componenta\DI;

/** Resolves callable representations and executes them through DI value resolution. */
interface CallableExecutorInterface extends CallableResolverInterface
{
    public function execute(
        mixed $callable,
        ResolutionContext $context = new ResolutionContext(),
    ): mixed;
}

<?php

declare(strict_types=1);

namespace Componenta\DI\Exception;

/**
 * Marker interface for any failure that happens while turning a value into a
 * callable or invoking one.
 *
 * Replaces the prior split between `CallableResolverExceptionInterface` and
 * `CallableInvokerExceptionInterface` - no consumer relied on telling "cannot
 * resolve" apart from "cannot invoke" at the type level, so one marker covers
 * the whole callable pipeline.
 */
interface CallableExceptionInterface extends ExceptionInterface
{
    /**
     * The original callable that failed.
     */
    public mixed $callable { get; }
}

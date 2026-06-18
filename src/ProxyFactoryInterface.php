<?php

declare(strict_types=1);

namespace Componenta\DI;

/**
 * Convenience aggregate of {@see LazyObjectFactoryInterface} and
 * {@see VirtualProxyFactoryInterface}.
 *
 * Components that need to choose between the two strategies per call
 * depend on this combined contract, while components that need only one
 * capability may depend on the narrower interface.
 *
 * Implementations are free to use any mechanism that satisfies both
 * contracts - generated subclasses, bytecode instrumentation, alternative
 * runtimes. The reference implementation on PHP 8.4 wraps
 * {@see \ReflectionClass::newLazyGhost()} and
 * {@see \ReflectionClass::newLazyProxy()}.
 */
interface ProxyFactoryInterface extends
    LazyObjectFactoryInterface,
    VirtualProxyFactoryInterface
{
}

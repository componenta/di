<?php

declare(strict_types=1);

namespace Componenta\DI;

use Componenta\DI\Exception\ResolutionException;

/**
 * Creates object instances with dependency injection.
 */
interface FactoryInterface
{
    /**
     * Creates a new instance of the specified class.
     *
     * Always returns a fresh instance - never consults or populates the
     * container's resolved-entry cache. Delegators are not applied; for
     * lazy wrappers use {@see ProxyFactoryInterface::makeLazy()} or
     * {@see ProxyFactoryInterface::makeProxy()} explicitly.
     *
     * @template T of object
     *
     * @param class-string<T>|string $entry Class name or service identifier.
     * @param array<string|int, mixed> $params Resolution context forwarded to
     *                                         the entry resolver. Reflection-based
     *                                         entries use it for constructor, property,
     *                                         and setup parameters; factories receive
     *                                         it as their second argument.
     *
     * @return T The created instance.
     *
     * @throws ResolutionException If instantiation fails.
     */
    public function make(string $entry, array $params = []): object;
}

<?php

declare(strict_types=1);

namespace Componenta\DI;

use Componenta\DI\Exception\ResolutionException;

/** Creates object instances with dependency injection. */
interface FactoryInterface
{
    /**
     * Performs an uncached resolution of the specified entry.
     *
     * The container itself neither reads nor populates its shared-entry cache
     * on this path. Object identity is still controlled by the selected
     * resolver or user factory, which may deliberately return an existing
     * object. Delegators are not applied.
     *
     * @param class-string|non-empty-string $entry Class name or service identifier.
     * @param array<string|int, mixed> $params Resolution context forwarded to
     *                                         the selected resolver or factory.
     * @throws ResolutionException If instantiation fails.
     */
    public function make(string $entry, array $params = []): object;
}

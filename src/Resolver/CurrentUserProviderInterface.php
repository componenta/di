<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver;

/**
 * Provides access to the currently authenticated user.
 *
 * Implementations should handle user storage and retrieval
 * for the current request/session context.
 *
 */
interface CurrentUserProviderInterface
{
    /**
     * Returns currently authenticated user or null if not authenticated.
     */
    public function getUser(): ?object;

    /**
     * Sets the current user for the request context.
     */
    public function setUser(?object $user): void;
}
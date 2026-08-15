<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\DI\Exception\ExceptionInterface;
use Componenta\DI\Exception\ResolutionException;
use Psr\Container\NotFoundExceptionInterface;

/** Resolves container entries by identifier. */
interface EntryResolverInterface
{
    public function can(string $id): bool;

    /**
     * @param array<string|int, mixed> $context Additional context for resolution.
     * @throws NotFoundExceptionInterface If the entry is not defined.
     * @throws ResolutionException If resolution fails.
     * @throws ExceptionInterface For any other container error.
     */
    public function resolve(string $id, array $context = []): mixed;
}

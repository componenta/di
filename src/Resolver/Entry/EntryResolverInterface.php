<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\DI\Exception\ExceptionInterface;
use Componenta\DI\Exception\ResolutionException;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Resolves container entries by identifier.
 *
 * The contract is intentionally minimal: a resolver only needs to report
 * whether it can handle an id and produce the resolved value when asked.
 *
 * Resolvers that also accept explicit {@see \Componenta\DI\Definition\DefinitionInterface}
 * instances implement {@see DefinitionAwareResolverInterface} in addition.
 */
interface EntryResolverInterface
{
    /**
     * Checks if this resolver can handle the given entry.
     */
    public function can(string $id): bool;

    /**
     * Resolves an entry to its value.
     *
     * Either returns the resolved value or throws.
     *
     * @param string               $id      Entry identifier.
     * @param array<string, mixed> $context Additional context for resolution.
     *
     * @return mixed Resolved value.
     *
     * @throws NotFoundExceptionInterface If the entry is not defined.
     * @throws ResolutionException      If resolution fails.
     * @throws ExceptionInterface         For any other container error.
     */
    public function resolve(string $id, array $context = []): mixed;
}

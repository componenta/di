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
     * @param array<string|int, mixed> $params
     * @throws NotFoundExceptionInterface
     * @throws ResolutionException
     * @throws ExceptionInterface
     */
    public function resolve(string $id, array $params = []): mixed;
}

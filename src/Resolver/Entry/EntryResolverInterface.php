<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\DI\Exception\ExceptionInterface;

/** Resolves container entries by identifier. */
interface EntryResolverInterface
{
    public function can(string $id): bool;

    /**
     * @param array<string|int, mixed> $params
     * @throws ExceptionInterface
     */
    public function resolve(string $id, array $params = []): mixed;
}

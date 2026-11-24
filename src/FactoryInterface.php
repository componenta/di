<?php

declare(strict_types=1);

namespace Componenta\DI;

use Componenta\DI\Exception\ExceptionInterface;

/** Resolves objects through the DI construction pipeline. Factories can reuse instances. */
interface FactoryInterface
{
    /**
     * @param non-empty-string $entry
     * @param array<string|int, mixed> $params
     * @throws ExceptionInterface Any failure owned or normalized by DI resolution.
     */
    public function make(string $entry, array $params = []): object;
}

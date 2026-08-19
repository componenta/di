<?php

declare(strict_types=1);

namespace Componenta\DI;

use Componenta\DI\Exception\ExceptionInterface;

/** Creates fresh object instances through the DI resolution pipeline. */
interface FactoryInterface
{
    /**
     * @param class-string|non-empty-string $entry
     * @param array<string|int, mixed> $params
     * @throws ExceptionInterface Any failure owned or normalized by DI resolution.
     */
    public function make(string $entry, array $params = []): object;
}

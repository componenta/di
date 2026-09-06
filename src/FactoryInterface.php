<?php

declare(strict_types=1);

namespace Componenta\DI;

use Componenta\DI\Exception\ExceptionInterface;

/** Creates fresh object instances through the DI resolution pipeline. */
interface FactoryInterface
{
    /**
     * @template T of object
     * @param class-string<T>|non-empty-string $entry
     * @param array<string|int, mixed> $params
     * @return ($entry is class-string<T> ? T : object)
     * @throws ExceptionInterface Any failure owned or normalized by DI resolution.
     */
    public function make(string $entry, array $params = []): object;
}

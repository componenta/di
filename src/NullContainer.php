<?php

declare(strict_types=1);

namespace Componenta\DI;

use Componenta\DI\Exception\NotFoundException;
use Psr\Container\ContainerInterface;

/**
 * Null Object implementation of ContainerInterface.
 *
 * Used as default container when none is provided.
 * Always returns false for has() and throws NotFoundException for get().
 */
final class NullContainer implements ContainerInterface
{
    public function get(string $id): never
    {
        throw NotFoundException::forService($id);
    }

    public function has(string $id): bool
    {
        return false;
    }
}

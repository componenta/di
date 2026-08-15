<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixture;

final class MagicInstanceCallable
{
    /** @param array<int, mixed> $arguments */
    public function __call(string $name, array $arguments): array
    {
        return [$name, $arguments];
    }
}

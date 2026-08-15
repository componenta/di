<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter\Request;

interface MapperInterface
{
    /**
     * @param array<string|int, mixed> $data
     * @return array<string|int, mixed>
     */
    public function transform(array $data): array;
}

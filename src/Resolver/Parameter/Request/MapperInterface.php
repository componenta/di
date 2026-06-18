<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter\Request;

interface MapperInterface
{
    public function transform(array $data): array;
}

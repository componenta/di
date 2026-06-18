<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter\Request;

use Psr\Http\Message\ServerRequestInterface;

interface RequestDataExtractorInterface
{
    public function extract(ServerRequestInterface $request): array;
}

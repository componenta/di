<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter\Request;

use Componenta\DI\Resolver\Parameter\ParameterSourceAttributeInterface;
use Psr\Http\Message\ServerRequestInterface;

interface ExtractorInterface extends ParameterSourceAttributeInterface
{
    public function extract(ServerRequestInterface $request): mixed;
}

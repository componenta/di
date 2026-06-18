<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter\Request;

use Psr\Http\Message\ServerRequestInterface;

interface ExtractorInterface
{
    /**
     * Extracts a single value from the PSR-7 request.
     *
     * @param ServerRequestInterface $request The PSR-7 request
     * @return mixed The extracted value
     */
    public function extract(ServerRequestInterface $request): mixed;
}

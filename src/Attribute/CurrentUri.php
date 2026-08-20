<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

use Attribute;
use Componenta\DI\Resolver\Parameter\Request\ExtractorInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

/** Provides the URI from the PSR-7 request associated with the current DI call. */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class CurrentUri implements ExtractorInterface
{
    public function extract(ServerRequestInterface $request): UriInterface
    {
        return $request->getUri();
    }
}

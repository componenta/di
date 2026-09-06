<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

use Attribute;
use Componenta\DI\Resolver\Parameter\Request\ExtractorInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Provides the PSR-7 request associated with the current DI call. */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class CurrentRequest implements ExtractorInterface
{
    public function extract(ServerRequestInterface $request): ServerRequestInterface
    {
        return $request;
    }
}

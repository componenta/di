<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

use Componenta\DI\Resolver\Parameter\Request\RequestDataExtractorInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Map* attribute that exposes the request's full attribute bag, plus any data
 * sources configured through {@see ExtractsRequestData}.
 *
 * The attribute bag always wins over parent-extracted keys: this preserves the
 * "give me everything in the request" intent while still letting subclasses
 * pull in uploaded files alongside.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
class MapRequestAttributes extends RequestMapper implements RequestDataExtractorInterface
{
    use ExtractsRequestData;

    public function extract(ServerRequestInterface $request): array
    {
        return [...$this->extractSharedData($request), ...$request->getAttributes()];
    }
}

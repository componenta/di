<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

use Componenta\DI\Resolver\Parameter\Request\RequestDataExtractorInterface;
use Psr\Http\Message\ServerRequestInterface;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
class MapServerParams extends RequestMapper implements RequestDataExtractorInterface
{
    use ExtractsRequestData;

    public function extract(ServerRequestInterface $request): array
    {
        return $this->mergeRequestData([
            ...$this->extractSharedSources($request),
            'server parameters' => $request->getServerParams(),
        ]);
    }
}

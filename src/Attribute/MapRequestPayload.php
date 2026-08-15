<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

use Componenta\DI\Resolver\Parameter\Request\RequestDataExtractorInterface;
use Psr\Http\Message\ServerRequestInterface;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
class MapRequestPayload extends RequestMapper implements RequestDataExtractorInterface
{
    use ExtractsRequestData;

    public function extract(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody() ?? [];

        return $this->mergeRequestData([
            ...$this->extractSharedSources($request),
            'parsed body' => is_array($body) ? $body : get_object_vars($body),
        ]);
    }
}

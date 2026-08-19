<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

use Componenta\DI\Resolver\Parameter\Request\RequestDataExtractorInterface;
use Psr\Http\Message\ServerRequestInterface;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
class MapHeaders extends RequestMapper implements RequestDataExtractorInterface
{
    use ExtractsRequestData;

    public function extract(ServerRequestInterface $request): array
    {
        $headers = array_map(
            static fn(array $values): string => implode(', ', $values),
            $request->getHeaders(),
        );

        return $this->mergeRequestData([
            ...$this->extractSharedSources($request),
            'headers' => $headers,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

use Componenta\DI\Resolver\Parameter\Request\RequestDataExtractorInterface;
use Psr\Http\Message\ServerRequestInterface;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
class MapCookies extends RequestMapper implements RequestDataExtractorInterface
{
    use ExtractsRequestData;

    public function extract(ServerRequestInterface $request): array
    {
        $data = $this->extractSharedData($request);
        return array_merge($data, $request->getCookieParams());
    }
}

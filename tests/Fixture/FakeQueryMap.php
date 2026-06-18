<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixture;

use Componenta\DI\Resolver\Parameter\Request\MapperInterface;
use Componenta\DI\Resolver\Parameter\Request\RequestDataExtractorInterface;
use Psr\Http\Message\ServerRequestInterface;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class FakeQueryMap implements RequestDataExtractorInterface, MapperInterface
{
    public function extract(ServerRequestInterface $request): array
    {
        return $request->getQueryParams();
    }

    public function transform(array $data): array
    {
        return $data;
    }
}

<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixture;

use Componenta\DI\Resolver\Parameter\Request\CastableInterface;
use Componenta\DI\Resolver\Parameter\Request\ExtractorInterface;
use Componenta\DI\Resolver\Parameter\Request\RequestResolver;
use Psr\Http\Message\ServerRequestInterface;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class FakeQueryParam implements ExtractorInterface, CastableInterface
{
    public function __construct(
        public ?string $cast = null,
    ) {}

    public function extract(ServerRequestInterface $request): mixed
    {
        $paramName = $request->getAttribute(RequestResolver::PARAMETER_NAME_ATTRIBUTE);
        $params = $request->getQueryParams();
        return $params[$paramName] ?? null;
    }
}

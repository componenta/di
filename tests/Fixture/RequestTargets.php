<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixture;

use Psr\Http\Message\UriInterface;

final class RequestTargets
{
    public function singleValue(
        #[FakeQueryParam] string $q,
        #[FakeQueryParam(cast: 'int')] string $page, // ExtractorInterface + CastableInterface
        UriInterface $uri, // Type-based resolution
        string $plain, // no request attribute, no UriInterface
    ): void {}

    public function mapperToArray(#[FakeQueryMap] array $params): void {}

    public function mapperToDto(#[FakeQueryMap] RequestDtoTarget $dto): void {}
}

<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixture;

use Componenta\Config\ConfigPath;
use Componenta\DI\Attribute\Cookie;
use Componenta\DI\Attribute\Header;
use Componenta\DI\Attribute\MapHeaders;
use Componenta\DI\Attribute\MapQueryString;
use Componenta\DI\Attribute\PayloadParam;
use Componenta\DI\Attribute\QueryParam;
use Componenta\DI\Attribute\RequestAttribute;
use Componenta\DI\Attribute\ServerParam;
use Componenta\DI\Attribute\UploadedFile;
use Psr\Http\Message\UploadedFileInterface;

final class HeaderFixture
{
    public function handle(#[Header('X-Api-Key')] string $apiKey): void {}
}

final class HeaderDefaultFixture
{
    public function handle(#[Header('X-Api-Key', default: 'default-key')] string $apiKey): void {}
}

final class HeaderNullableFixture
{
    public function handle(#[Header('X-Api-Key')] ?string $apiKey): void {}
}

final class CookieFixture
{
    public function handle(#[Cookie('session_id')] string $session): void {}
}

final class CookieNullableFixture
{
    public function handle(#[Cookie('session_id', default: 'fallback')] ?string $session): void {}
}

final class ServerParamFixture
{
    public function handle(#[ServerParam('REMOTE_ADDR')] string $ip): void {}
}

final class UploadedFileFixture
{
    public function handle(#[UploadedFile('avatar')] UploadedFileInterface $file): void {}
}

final class RequestAttributeFixture
{
    public function handle(#[RequestAttribute('user_id')] int $userId): void {}
}

final class RequestAttributeNullableFixture
{
    public function handle(#[RequestAttribute('nickname', default: 'fallback')] ?string $nickname): void {}
}

final class RequestAttributeImplicitFixture
{
    public function handle(#[RequestAttribute] int $userId): void {}
}

final class QueryParamFixture
{
    public function handle(#[QueryParam('page')] string $currentPage): void {}
}

final class QueryParamImplicitFixture
{
    public function handle(#[QueryParam] string $limit): void {}
}

final class PayloadParamFixture
{
    public function handle(#[PayloadParam('user_name')] string $name): void {}
}

final class PayloadParamNullableFixture
{
    public function handle(#[PayloadParam('nickname', default: 'fallback')] ?string $nickname): void {}
}

final class PayloadParamPathNullableFixture
{
    public function handle(#[PayloadParam(new ConfigPath('user.profile.nickname'), default: 'fallback')] ?string $nickname): void {}
}

final class PayloadParamPathFixture
{
    public function handle(#[PayloadParam(new ConfigPath('user.profile.name'))] string $name): void {}
}

final class MapQueryStringArrayFixture
{
    public function handle(#[MapQueryString] array $query): void {}
}

class QueryDto
{
    public string $page;
}

final class MapQueryStringDtoFixture
{
    public function handle(#[MapQueryString] QueryDto $query): void {}
}

final class MapHeadersMappingFixture
{
    public function handle(#[MapHeaders(['Content-Type' => 'contentType', 'X-Request-Id' => 'requestId'])] array $headers): void {}
}

final class NoRequestAttributeFixture
{
    public function handle(string $value): void {}
}

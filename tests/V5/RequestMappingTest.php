<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\Caster\CasterProviderInterface;
use Componenta\DI\Attribute\Cast;
use Componenta\DI\Attribute\Header;
use Componenta\DI\Attribute\MapRequest;
use Componenta\DI\Attribute\RequestDataSource;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\RequestDataConflictException;
use Componenta\DI\Exception\ValueProviderConflictException;
use Componenta\DI\ResolutionContext;
use Componenta\DI\Tests\Support\TestCasterProvider;
use Nyholm\Psr7\ServerRequest;

final class HeaderCastDto
{
    public function __construct(
        #[Header('X-Count'), Cast('int')]
        public int $count,
    ) {}
}

final class MappedPayloadDto
{
    public function __construct(public string $name) {}
}

final class MappedPayloadEnvelope
{
    public function __construct(#[MapRequest] public MappedPayloadDto $dto) {}
}

final class HeaderProtectedMappedDto
{
    public function __construct(#[Header('X-Token')] public string $token) {}
}

final class HeaderProtectedEnvelope
{
    public function __construct(#[MapRequest] public HeaderProtectedMappedDto $dto) {}
}

final class MultiSourceEnvelope
{
    /** @param array<string,mixed> $data */
    public function __construct(
        #[MapRequest(sources: [RequestDataSource::Payload, RequestDataSource::Query])]
        public array $data,
    ) {}
}

function requestContainer(): \Componenta\DI\Container
{
    return (new ContainerBuilder())
        ->addService(CasterProviderInterface::class, new TestCasterProvider())
        ->build();
}

test('request providers feed the same transformer pipeline', function (): void {
    $request = (new ServerRequest('GET', '/'))->withHeader('X-Count', '41');
    $dto = requestContainer()->make(
        HeaderCastDto::class,
        ResolutionContext::mapped([], $request),
    );

    expect($dto->count)->toBe(41);
});

test('MapRequest creates nested DTOs through mapped provenance', function (): void {
    $request = (new ServerRequest('POST', '/'))->withParsedBody(['name' => 'Ada']);
    $entry = requestContainer()->make(
        MappedPayloadEnvelope::class,
        ResolutionContext::mapped([], $request),
    );

    expect($entry->dto->name)->toBe('Ada');
});

test('nested mapped DTO input cannot shadow its declared providers', function (): void {
    $request = (new ServerRequest('POST', '/'))
        ->withHeader('X-Token', 'trusted')
        ->withParsedBody(['token' => 'attacker']);

    expect(fn() => requestContainer()->make(
        HeaderProtectedEnvelope::class,
        ResolutionContext::mapped([], $request),
    ))->toThrow(ValueProviderConflictException::class);
});

test('MapRequest rejects conflicting values from multiple sources by default', function (): void {
    $request = (new ServerRequest('POST', '/?id=2'))
        ->withQueryParams(['id' => 2])
        ->withParsedBody(['id' => 1]);

    expect(fn() => requestContainer()->make(
        MultiSourceEnvelope::class,
        ResolutionContext::mapped([], $request),
    ))->toThrow(RequestDataConflictException::class);
});

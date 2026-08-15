<?php

declare(strict_types=1);

use Componenta\DI\Attribute\MapQueryString;
use Componenta\DI\Attribute\MapRequestPayload;
use Componenta\DI\Exception\RequestDataConflictException;
use Componenta\DI\Resolver\Parameter\Request\RequestDataConflictPolicy;
use Componenta\DI\Tests\Fixture\FakeServerRequest as ServerRequest;
use Componenta\DI\Tests\Fixture\FakeUploadedFile;

final class PayloadWithRouteId extends MapRequestPayload
{
    protected array $attributes = ['id'];
}

final class QueryWithRouteId extends MapQueryString
{
    protected array $attributes = ['id'];
}

final class PayloadWithSharedIdSources extends MapRequestPayload
{
    protected array $attributes = ['id'];
    protected array $files = ['id'];
}

it('rejects a payload value that conflicts with a request attribute', function (): void {
    $request = (new ServerRequest('POST', '/posts/10'))
        ->withAttribute('id', 10)
        ->withParsedBody(['id' => 20]);

    expect(fn() => (new PayloadWithRouteId())->extract($request))
        ->toThrow(RequestDataConflictException::class, 'request attributes and parsed body');
});

it('rejects a query value that conflicts with a request attribute', function (): void {
    $request = (new ServerRequest('GET', '/posts/10?id=20'))
        ->withAttribute('id', 10)
        ->withQueryParams(['id' => 20]);

    expect(fn() => (new QueryWithRouteId())->extract($request))
        ->toThrow(RequestDataConflictException::class, 'request attributes and query string');
});

it('rejects a conflict between shared request attributes and uploaded files', function (): void {
    $request = (new ServerRequest('POST', '/posts/10'))
        ->withAttribute('id', 10)
        ->withUploadedFiles(['id' => new FakeUploadedFile()]);

    expect(fn() => (new PayloadWithSharedIdSources())->extract($request))
        ->toThrow(RequestDataConflictException::class, 'request attributes and uploaded files');
});

it('accepts the same value repeated by two request sources', function (): void {
    $request = (new ServerRequest('POST', '/posts/10'))
        ->withAttribute('id', 10)
        ->withParsedBody(['id' => 10]);

    expect((new PayloadWithRouteId())->extract($request))->toBe(['id' => 10]);
});

it('can explicitly preserve the trusted first source', function (): void {
    $request = (new ServerRequest('POST', '/posts/10'))
        ->withAttribute('id', 10)
        ->withParsedBody(['id' => 20]);

    $mapper = new PayloadWithRouteId(
        conflictPolicy: RequestDataConflictPolicy::FirstWins,
    );

    expect($mapper->extract($request))->toBe(['id' => 10]);
});

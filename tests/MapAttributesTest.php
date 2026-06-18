<?php

declare(strict_types=1);

use Componenta\DI\Attribute\MapCookies;
use Componenta\DI\Attribute\MapHeaders;
use Componenta\DI\Attribute\MapQueryString;
use Componenta\DI\Attribute\MapRequestAttributes;
use Componenta\DI\Attribute\MapRequestPayload;
use Componenta\DI\Attribute\MapServerParams;
use Componenta\DI\Attribute\MapUploadedFiles;
use Componenta\DI\Resolver\Parameter\Request\MapperInterface;
use Componenta\DI\Resolver\Parameter\Request\RequestDataExtractorInterface;
use Componenta\DI\Tests\Fixture\FakeServerRequest as ServerRequest;
use Componenta\DI\Tests\Fixture\FakeUploadedFile as UploadedFile;
use Psr\Http\Message\ServerRequestInterface;

function mapAttribute(RequestDataExtractorInterface&MapperInterface $mapper, ServerRequestInterface $request): array
{
    return $mapper->transform($mapper->extract($request));
}

final class MapPayloadWithNullableSharedAttribute extends MapRequestPayload
{
    protected array $attributes = ['nickname'];
}

describe('Attribute\\MapQueryString', function () {
    it('extracts the query string parameters into the data array', function () {
        $request = (new ServerRequest('GET', '/?a=1&b=2'))
            ->withQueryParams(['a' => '1', 'b' => '2']);

        expect(mapAttribute(new MapQueryString(), $request))->toBe(['a' => '1', 'b' => '2']);
    });

    it('returns an empty array when there are no query parameters', function () {
        expect(mapAttribute(new MapQueryString(), new ServerRequest('GET', '/')))->toBe([]);
    });
});

describe('Attribute\\MapRequestPayload', function () {
    it('extracts a parsed body array', function () {
        $request = (new ServerRequest('POST', '/'))
            ->withParsedBody(['title' => 'hello', 'body' => 'text']);

        expect(mapAttribute(new MapRequestPayload(), $request))->toBe(['title' => 'hello', 'body' => 'text']);
    });

    it('treats a null parsed body as an empty array (no merge error)', function () {
        $request = (new ServerRequest('POST', '/'))->withParsedBody(null);

        expect(mapAttribute(new MapRequestPayload(), $request))->toBe([]);
    });

    it('flattens a parsed body object via get_object_vars', function () {
        $body = new stdClass();
        $body->name = 'Alice';
        $body->age = 30;
        $request = (new ServerRequest('POST', '/'))->withParsedBody($body);

        expect(mapAttribute(new MapRequestPayload(), $request))->toBe(['name' => 'Alice', 'age' => 30]);
    });

    it('preserves selected request attributes with explicit null values', function () {
        $request = (new ServerRequest('POST', '/'))
            ->withAttribute('nickname', null)
            ->withParsedBody(['title' => 'hello']);

        expect(mapAttribute(new MapPayloadWithNullableSharedAttribute(), $request))
            ->toBe(['nickname' => null, 'title' => 'hello']);
    });
});

describe('Attribute\\MapCookies', function () {
    it('extracts the cookie parameters into the data array', function () {
        $request = (new ServerRequest('GET', '/'))
            ->withCookieParams(['session' => 'abc', 'lang' => 'en']);

        expect(mapAttribute(new MapCookies(), $request))
            ->toBe(['session' => 'abc', 'lang' => 'en']);
    });

    it('returns an empty array when no cookies are present', function () {
        expect(mapAttribute(new MapCookies(), new ServerRequest('GET', '/')))->toBe([]);
    });
});

describe('Attribute\\MapHeaders', function () {
    it('joins multi-value headers with ", "', function () {
        $request = (new ServerRequest('GET', '/'))
            ->withHeader('Accept', ['application/json', 'text/html']);

        $result = mapAttribute(new MapHeaders(), $request);

        expect($result)->toHaveKey('Accept')
            ->and($result['Accept'])->toBe('application/json, text/html');
    });

    it('extracts single-value headers as plain strings', function () {
        $request = (new ServerRequest('GET', '/'))
            ->withHeader('X-Custom', 'value');

        expect(mapAttribute(new MapHeaders(), $request)['X-Custom'])->toBe('value');
    });
});

describe('Attribute\\MapRequestAttributes', function () {
    it('extracts the entire request attribute bag', function () {
        $request = (new ServerRequest('GET', '/'))
            ->withAttribute('userId', 42)
            ->withAttribute('traceId', 'xyz');

        expect(mapAttribute(new MapRequestAttributes(), $request))
            ->toBe(['userId' => 42, 'traceId' => 'xyz']);
    });

    it('returns an empty array when no request attributes are set', function () {
        expect(mapAttribute(new MapRequestAttributes(), new ServerRequest('GET', '/')))->toBe([]);
    });
});

describe('Attribute\\MapServerParams', function () {
    it('extracts the server params into the data array', function () {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            serverParams: ['REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '127.0.0.1'],
        );

        expect(mapAttribute(new MapServerParams(), $request))
            ->toBe(['REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '127.0.0.1']);
    });

    it('returns an empty array when no server params are set', function () {
        expect(mapAttribute(new MapServerParams(), new ServerRequest('GET', '/')))->toBe([]);
    });
});

describe('Attribute\\MapUploadedFiles', function () {
    it('extracts the uploaded-files bag into the data array', function () {
        $file = new UploadedFile();
        $request = (new ServerRequest('POST', '/'))
            ->withUploadedFiles(['avatar' => $file, 'doc' => $file]);

        expect(mapAttribute(new MapUploadedFiles(), $request))
            ->toBe(['avatar' => $file, 'doc' => $file]);
    });

    it('returns an empty array when there are no uploaded files', function () {
        expect(mapAttribute(new MapUploadedFiles(), new ServerRequest('POST', '/')))->toBe([]);
    });
});

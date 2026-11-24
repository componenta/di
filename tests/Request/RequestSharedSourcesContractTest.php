<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Request\RequestSharedSourcesContract;

use Componenta\DI\Attribute\MapCookies;
use Componenta\DI\Attribute\MapHeaders;
use Componenta\DI\Attribute\MapQueryString;
use Componenta\DI\Attribute\MapRequest;
use Componenta\DI\Attribute\MapRequestPayload;
use Componenta\DI\Attribute\MapServerParams;
use Componenta\DI\Attribute\MapUploadedFiles;
use Componenta\DI\Attribute\RequestDataSource;
use Componenta\DI\Exception\RequestDataConflictException;
use Componenta\DI\Resolver\Parameter\Request\RequestDataConflictPolicy;
use Componenta\DI\Resolver\Parameter\Request\RequestDataExtractorInterface;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Error;
use InvalidArgumentException;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\ServerRequestInterface;

final class SelectedCookieSources extends MapCookies
{
    protected array $attributes = ['route', 'missing'];
    protected array $files = ['upload', 'missing'];
}
final class SelectedHeaderSources extends MapHeaders
{
    protected array $attributes = ['route', 'missing'];
    protected array $files = ['upload', 'missing'];
}
final class SelectedQuerySources extends MapQueryString
{
    protected array $attributes = ['route', 'missing'];
    protected array $files = ['upload', 'missing'];
}
final class SelectedPayloadSources extends MapRequestPayload
{
    protected array $attributes = ['route', 'missing'];
    protected array $files = ['upload', 'missing'];
}
final class SelectedServerSources extends MapServerParams
{
    protected array $attributes = ['route', 'missing'];
    protected array $files = ['upload', 'missing'];
}
final class SelectedUploadSources extends MapUploadedFiles
{
    protected array $attributes = ['route', 'missing'];
    protected array $files = ['upload', 'missing'];
}

final class WildcardUploadSources extends MapUploadedFiles
{
    protected array $attributes = ['*'];
    protected array $files = ['*'];
}

final class InvalidAttributeSources extends MapUploadedFiles
{
    protected array $attributes = ['*', 'route'];
}

final class InvalidUploadSources extends MapUploadedFiles
{
    protected array $files = ['*', 'upload'];
}

test('specialized request mappers merge only selected shared sources with their own data', function (RequestDataExtractorInterface $mapper, array $own): void {
    $upload = new UploadedFile(Stream::create('selected'), 8, UPLOAD_ERR_OK);
    $other = new UploadedFile(Stream::create('private'), 7, UPLOAD_ERR_OK);
    $request = (new ServerRequest('POST', '/', ['X-Trace' => ['first', 'second']], '', '1.1', ['server' => 'server-data']))
        ->withQueryParams(['query' => 'query-data'])
        ->withCookieParams(['cookie' => 'cookie-data'])
        ->withParsedBody(['body' => 'body-data'])
        ->withAttribute('route', null)
        ->withAttribute('private', 'hidden')
        ->withUploadedFiles(['upload' => $upload, 'other' => $other]);

    expect($mapper->extract($request))->toBe(['route' => null, 'upload' => $upload, ...$own]);
})->with([
    [new SelectedCookieSources(), ['cookie' => 'cookie-data']],
    [new SelectedHeaderSources(), ['X-Trace' => 'first, second']],
    [new SelectedQuerySources(), ['query' => 'query-data']],
    [new SelectedPayloadSources(), ['body' => 'body-data']],
    [new SelectedServerSources(), ['server' => 'server-data']],
    [new SelectedUploadSources(), []],
]);

test('wildcard shared selectors include every request attribute and upload', function (): void {
    $upload = new UploadedFile(Stream::create('data'), 4, UPLOAD_ERR_OK);
    $request = (new ServerRequest('GET', '/'))
        ->withAttribute('first', null)
        ->withAttribute('second', false)
        ->withUploadedFiles(['upload' => $upload]);

    expect((new WildcardUploadSources())->extract($request))
        ->toBe(['first' => null, 'second' => false, 'upload' => $upload]);
});

test('a wildcard cannot be combined with individual shared source selectors', function (RequestDataExtractorInterface $mapper, string $message): void {
    expect(fn() => $mapper->extract(new ServerRequest('GET', '/')))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    [new InvalidAttributeSources(), 'Request attribute wildcard must be the only selector.'],
    [new InvalidUploadSources(), 'Uploaded-file wildcard must be the only selector.'],
]);

test('the built-in uploaded-files attribute resolves the request upload tree through call', function (): void {
    $upload = new UploadedFile(Stream::create('data'), 4, UPLOAD_ERR_OK);
    $files = ['attachments' => ['first' => $upload]];
    $request = (new ServerRequest('POST', '/'))->withUploadedFiles($files);
    $container = (new ContainerBuilder())->build();

    expect($container->call(
        static fn(#[MapUploadedFiles] array $files): array => $files,
        [ServerRequestInterface::class => $request],
    ))->toBe($files);
});

test('FirstWins ignores later recursive array values from request sources', function (bool $queryFirst): void {
    $queryOwner = new \stdClass();
    $queryValue = [];
    $queryValue['self'] = &$queryValue;
    $queryValue['owner'] = $queryOwner;
    $payloadOwner = new \stdClass();
    $payloadValue = [];
    $payloadValue['self'] = &$payloadValue;
    $payloadValue['owner'] = $payloadOwner;
    $request = (new ServerRequest('POST', '/'))
        ->withQueryParams(['value' => $queryValue])
        ->withParsedBody(['value' => $payloadValue]);
    $target = $queryFirst
        ? static fn(#[MapRequest(sources: [RequestDataSource::Query, RequestDataSource::Payload], conflictPolicy: RequestDataConflictPolicy::FirstWins)] array $data): array => $data
        : static fn(#[MapRequest(sources: [RequestDataSource::Payload, RequestDataSource::Query], conflictPolicy: RequestDataConflictPolicy::FirstWins)] array $data): array => $data;

    $result = (new ContainerBuilder())->build()->call($target, [ServerRequestInterface::class => $request]);
    if (!is_array($result) || !is_array($result['value'] ?? null)) {
        throw new \LogicException('Expected the selected request array.');
    }
    $value = $result['value'];
    if (!is_array($value['self'] ?? null)) {
        throw new \LogicException('Expected the original recursive request value.');
    }
    $expected = $queryFirst ? $queryOwner : $payloadOwner;
    expect(array_keys($result))->toBe(['value'])
        ->and($value['owner'])->toBe($expected)
        ->and($value['self']['owner'])->toBe($expected);
})->with(['query first' => true, 'payload first' => false]);

test('Reject reports uncomparable recursive arrays as conflicts and accepts a retry with one shared value', function (): void {
    $payload = ['tag' => 'mapped'];
    $payload['self'] = &$payload;
    $query = ['tag' => 'mapped'];
    $query['self'] = &$query;
    $request = (new ServerRequest('POST', '/'))
        ->withParsedBody(['value' => $payload])
        ->withQueryParams(['value' => $query]);
    $container = (new ContainerBuilder())->build();
    $target = static fn(#[MapRequest(sources: [RequestDataSource::Payload, RequestDataSource::Query])] array $data): array => $data;

    try {
        $container->call($target, [ServerRequestInterface::class => $request]);
        Assert::fail('The request mapping must report a conflicting source.');
    } catch (RequestDataConflictException $error) {
        expect($error->key)->toBe('value')
            ->and($error->existingSource)->toBe('parsed body')
            ->and($error->incomingSource)->toBe('query string')
            ->and($error->getPrevious())->toBeInstanceOf(Error::class);
    }

    $sameValueRequest = $request->withQueryParams(['value' => $payload]);
    $result = $container->call($target, [ServerRequestInterface::class => $sameValueRequest]);
    if (!is_array($result) || !is_array($result['value']) || !is_array($result['value']['self'])) {
        throw new \LogicException('Expected the shared recursive request value.');
    }
    expect($result['value']['self']['tag'])->toBe('mapped');
});

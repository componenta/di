<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\DI\Attribute\MapCookies;
use Componenta\DI\Attribute\MapHeaders;
use Componenta\DI\Attribute\MapQueryString;
use Componenta\DI\Attribute\MapRequestPayload;
use Componenta\DI\Attribute\MapServerParams;
use Componenta\DI\Attribute\MapUploadedFiles;
use Componenta\DI\Resolver\Parameter\Request\RequestDataExtractorInterface;
use Componenta\DI\Tests\Support\ContainerBuilder;
use InvalidArgumentException;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
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

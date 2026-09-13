<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\DI\Attribute\Make;
use Componenta\DI\Attribute\MapRequest;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Attribute\RequestDataSource;
use Componenta\DI\Exception\RequestDataConflictException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use InvalidArgumentException;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;

class ExplicitProxyBase
{
    public int $value = 17;
}

final class ExplicitProxyChild extends ExplicitProxyBase {}

final class ExplicitProxyOwner
{
    #[Make('proxy-product'), Proxy(ExplicitProxyChild::class)]
    public ExplicitProxyBase $product;
}

test('an explicit concrete proxy class takes precedence over a concrete declared parent type', function (bool $property): void {
    $container = (new ContainerBuilder())
        ->addFactory('proxy-product', static fn(): object => new ExplicitProxyChild())
        ->build();
    $product = $property
        ? $container->make(ExplicitProxyOwner::class)->product
        : $container->call(static fn(#[Make('proxy-product'), Proxy(ExplicitProxyChild::class)] ExplicitProxyBase $product): ExplicitProxyBase => $product);

    if (!$product instanceof ExplicitProxyBase) {
        throw new \LogicException('Expected the configured proxy type.');
    }
    expect($product)->toBeInstanceOf(ExplicitProxyChild::class)
        ->and($product->value)->toBe(17);
})->with([false, true]);

test('request mapping adds selected uploads independently of the primary source list', function (bool $explicitFiles): void {
    $upload = new UploadedFile(Stream::create('file'), 4, UPLOAD_ERR_OK);
    $hidden = new UploadedFile(Stream::create('private'), 7, UPLOAD_ERR_OK);
    $request = (new ServerRequest('POST', '/'))
        ->withQueryParams(['query' => 'value'])
        ->withUploadedFiles(['upload' => $upload, 'hidden' => $hidden]);
    $mapper = new MapRequest(
        sources: $explicitFiles ? [RequestDataSource::Files, RequestDataSource::Query] : [RequestDataSource::Query],
        files: ['upload', 'missing'],
    );

    expect($mapper->extract($request))->toBe(['upload' => $upload, 'query' => 'value']);
})->with([false, true]);

test('request mapping reports the selected source when a wildcard is mixed with a name', function (bool $files): void {
    $mapper = new MapRequest(
        sources: [RequestDataSource::Query],
        attributes: $files ? [] : ['*', 'route'],
        files: $files ? ['*', 'upload'] : [],
    );

    expect(fn() => $mapper->extract(new ServerRequest('GET', '/')))
        ->toThrow(InvalidArgumentException::class, ($files ? 'Uploaded files' : 'Request attributes') . ' wildcard must be the only selector.');
})->with([false, true]);

test('conflicting request sources retain the key and both source names in their diagnostic', function (): void {
    $mapper = new MapRequest(sources: [RequestDataSource::Query, RequestDataSource::Cookies]);
    $request = (new ServerRequest('GET', '/'))->withQueryParams(['key' => 'first'])->withCookieParams(['key' => 'second']);

    expect(fn() => $mapper->extract($request))->toThrow(
        RequestDataConflictException::class,
        'Request data key "key" is present in both query string and cookies with different values.',
    );
});

<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\DI\Attribute\Cookie;
use Componenta\DI\Attribute\Header;
use Componenta\DI\Attribute\QueryParam;
use Componenta\DI\Attribute\RequestAttribute;
use Componenta\DI\Attribute\ServerParam;
use Componenta\DI\Resolver\Parameter\Request\ExtractorInterface;
use Componenta\DI\Resolver\Parameter\Request\ParameterNameAwareExtractorInterface;
use Nyholm\Psr7\ServerRequest;

test('request extractors distinguish a missing required value from an explicit default', function (string $class, string $kind): void {
    if (!is_a($class, ExtractorInterface::class, true)) {
        throw new \LogicException('Expected an extractor fixture.');
    }
    $request = new ServerRequest('GET', '/');
    $required = new $class('missing');
    expect(fn() => $required->extract($request))->toThrow(\RuntimeException::class, 'Required ' . $kind . ' "missing" is missing');
    foreach ([null, false, 0, '', [], 'fallback'] as $default) {
        expect((new $class('missing', default: $default))->extract($request))->toBe($default);
    }
})->with([
    [Cookie::class, 'cookie'],
    [Header::class, 'header'],
    [QueryParam::class, 'query parameter'],
    [RequestAttribute::class, 'request attribute'],
    [ServerParam::class, 'server parameter'],
]);

test('parameter-aware extractors reject absent or empty names when invoked without a parameter', function (ParameterNameAwareExtractorInterface $extractor): void {
    expect(fn() => $extractor->extract(new ServerRequest('GET', '/')))
        ->toThrow(\LogicException::class, 'name must be a non-empty string');
})->with([[new QueryParam()], [new QueryParam('')], [new RequestAttribute()], [new RequestAttribute('')]]);

test('explicit null request values remain distinct from a missing source value', function (): void {
    $request = (new ServerRequest('GET', '/', serverParams: ['value' => null]))
        ->withQueryParams(['value' => null])
        ->withCookieParams(['value' => null])
        ->withAttribute('value', null)
        ->withHeader('Value', '');
    foreach ([new Cookie('value', 'fallback'), new QueryParam('value', 'fallback'), new RequestAttribute('value', 'fallback'), new ServerParam('value', 'fallback')] as $extractor) {
        expect($extractor->extract($request))->toBeNull();
    }
    expect((new Header('value', 'fallback'))->extract($request))->toBe('');
});

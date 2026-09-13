<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\Config\ConfigPath;
use Componenta\DI\Attribute\PayloadParam;
use Componenta\DI\Attribute\UploadedFile;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile as RequestUploadedFile;

test('payload extraction distinguishes an absent field from explicit null and literal dotted keys', function (): void {
    $request = (new ServerRequest('POST', '/'))->withParsedBody([
        'user' => ['name' => null],
        'user.name' => 'literal',
    ]);

    expect((new PayloadParam(new ConfigPath('user.name'), 'fallback'))->extract($request))->toBeNull()
        ->and((new PayloadParam('user.name'))->extract($request))->toBe('literal')
        ->and((new PayloadParam('missing', null))->extract($request))->toBeNull()
        ->and((new PayloadParam('missing', false))->extract($request))->toBeFalse();
});

test('payload paths use the default when any intermediate segment is absent or cannot be traversed', function (array $body): void {
    $request = (new ServerRequest('POST', '/'))->withParsedBody($body);

    expect((new PayloadParam(new ConfigPath('user.profile.name'), 'fallback'))->extract($request))->toBe('fallback');
})->with([
    'missing first segment' => [[]],
    'missing middle segment' => [['user' => []]],
    'missing last segment' => [['user' => ['profile' => []]]],
    'scalar middle segment' => [['user' => ['profile' => 'scalar']]],
    'null middle segment' => [['user' => ['profile' => null]]],
]);

test('payload extraction treats an absent parsed body as empty and requires a name outside parameter resolution', function (): void {
    $request = new ServerRequest('POST', '/');

    expect((new PayloadParam('name', 'fallback'))->extract($request))->toBe('fallback')
        ->and(fn() => (new PayloadParam('name'))->extract($request))
        ->toThrow(\RuntimeException::class, 'Required payload parameter "name" is missing')
        ->and(fn() => (new PayloadParam())->extract($request))
        ->toThrow(\LogicException::class, 'Payload parameter name must be a non-empty string')
        ->and(fn() => (new PayloadParam(''))->extract($request))
        ->toThrow(\LogicException::class, 'Payload parameter name must be a non-empty string');
});

test('uploaded file extraction preserves top-level collections and prefers literal dotted names', function (): void {
    $nested = new RequestUploadedFile(Stream::create('nested'), 6, UPLOAD_ERR_OK);
    $literal = new RequestUploadedFile(Stream::create('literal'), 7, UPLOAD_ERR_OK);
    $request = (new ServerRequest('POST', '/'))->withUploadedFiles([
        'files' => [2 => $nested],
        'files.2' => $literal,
    ]);

    expect((new UploadedFile('files'))->extract($request))->toBe([2 => $nested])
        ->and((new UploadedFile('files.2'))->extract($request))->toBe($literal)
        ->and((new UploadedFile('files.2'))->extract($request->withUploadedFiles(['files' => [2 => $nested]])))->toBe($nested)
        ->and((new UploadedFile('missing'))->extract($request))->toBeNull()
        ->and((new UploadedFile('files.3'))->extract($request))->toBeNull()
        ->and((new UploadedFile('files.2.extra'))->extract($request))->toBeNull();
});

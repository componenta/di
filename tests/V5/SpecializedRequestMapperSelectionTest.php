<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\DI\Attribute\MapRequestAttributes;
use Componenta\DI\Attribute\MapUploadedFiles;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;

final class SelectedRequestAttributesFixture extends MapRequestAttributes
{
    protected array $attributes = ['trusted'];
}

final class SelectedUploadedFilesFixture extends MapUploadedFiles
{
    protected array $files = ['avatar'];
}

function uploadedFileFixture(string $name): UploadedFileInterface
{
    return new class ($name) implements UploadedFileInterface {
        public function __construct(private readonly string $name) {}

        public function getStream(): StreamInterface
        {
            throw new \LogicException('Stream access is not required by this fixture.');
        }

        public function moveTo(string $targetPath): void {}

        public function getSize(): ?int
        {
            return 0;
        }

        public function getError(): int
        {
            return UPLOAD_ERR_OK;
        }

        public function getClientFilename(): ?string
        {
            return $this->name;
        }

        public function getClientMediaType(): ?string
        {
            return null;
        }
    };
}

test('MapRequestAttributes exposes the full bag by default and honors descendant selection', function (): void {
    $request = (new ServerRequest('GET', '/'))
        ->withAttribute('trusted', 'yes')
        ->withAttribute('private', 'hidden');

    expect((new MapRequestAttributes())->extract($request))
        ->toBe(['trusted' => 'yes', 'private' => 'hidden'])
        ->and((new SelectedRequestAttributesFixture())->extract($request))
        ->toBe(['trusted' => 'yes']);
});

test('MapUploadedFiles exposes the full bag by default and honors descendant selection', function (): void {
    $avatar = uploadedFileFixture('avatar.txt');
    $private = uploadedFileFixture('private.txt');
    $request = (new ServerRequest('POST', '/'))->withUploadedFiles([
        'avatar' => $avatar,
        'private' => $private,
    ]);

    expect((new MapUploadedFiles())->extract($request))
        ->toBe(['avatar' => $avatar, 'private' => $private])
        ->and((new SelectedUploadedFilesFixture())->extract($request))
        ->toBe(['avatar' => $avatar]);
});

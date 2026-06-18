<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixture;

use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use BadMethodCallException;

/**
 * Minimal UploadedFileInterface fake. Tests use it only as an identity
 * (is it the same instance that was put in?) - never actually read its
 * contents or move it.
 */
final class FakeUploadedFile implements UploadedFileInterface
{
    public function __construct(
        public readonly string $clientFilename = 'test.txt',
        public readonly int $error = UPLOAD_ERR_OK,
    ) {}

    public function getStream(): StreamInterface
    {
        throw new BadMethodCallException('getStream() not supported by fixture');
    }

    public function moveTo(string $targetPath): void
    {
        throw new BadMethodCallException('moveTo() not supported by fixture');
    }

    public function getSize(): ?int { return null; }
    public function getError(): int { return $this->error; }
    public function getClientFilename(): ?string { return $this->clientFilename; }
    public function getClientMediaType(): ?string { return null; }
}

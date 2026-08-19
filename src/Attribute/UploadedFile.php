<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

use Componenta\DI\Resolver\Parameter\Request\ExtractorInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
readonly class UploadedFile implements ExtractorInterface
{
    public function __construct(public string $name) {}

    /** @return UploadedFileInterface|array<string|int,mixed>|null */
    public function extract(ServerRequestInterface $request): UploadedFileInterface|array|null
    {
        $files = $request->getUploadedFiles();
        if (isset($files[$this->name])) {
            $file = $files[$this->name];
            return $file instanceof UploadedFileInterface || is_array($file) ? $file : null;
        }
        if (!str_contains($this->name, '.')) {
            return null;
        }

        $current = $files;
        foreach (explode('.', $this->name) as $segment) {
            if (!is_array($current) || !isset($current[$segment])) {
                return null;
            }
            $current = $current[$segment];
        }
        return $current instanceof UploadedFileInterface || is_array($current) ? $current : null;
    }
}

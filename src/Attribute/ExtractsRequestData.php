<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

use Psr\Http\Message\ServerRequestInterface;

trait ExtractsRequestData
{
    private const string WILDCARD = '*';

    /** @var list<string> */
    protected array $attributes = [];
    /** @var list<string> */
    protected array $files = [];

    /** @return array<string,array<string|int,mixed>> */
    protected function extractSharedSources(ServerRequestInterface $request): array
    {
        return [
            'request attributes' => $this->extractConfiguredRequestAttributes($request),
            'uploaded files' => $this->extractConfiguredUploadedFiles($request),
        ];
    }

    /** @return array<string|int,mixed> */
    protected function extractSharedData(ServerRequestInterface $request): array
    {
        return $this->mergeRequestData($this->extractSharedSources($request));
    }

    /** @return array<string|int,mixed> */
    private function extractConfiguredRequestAttributes(ServerRequestInterface $request): array
    {
        if ($this->attributes === [self::WILDCARD]) {
            return $request->getAttributes();
        }
        if ($this->attributes === []) {
            return [];
        }

        $data = [];
        $attributes = $request->getAttributes();
        foreach ($this->attributes as $attribute) {
            if (array_key_exists($attribute, $attributes)) {
                $data[$attribute] = $attributes[$attribute];
            }
        }
        return $data;
    }

    /** @return array<string|int,mixed> */
    private function extractConfiguredUploadedFiles(ServerRequestInterface $request): array
    {
        if ($this->files === [self::WILDCARD]) {
            return $request->getUploadedFiles();
        }
        if ($this->files === []) {
            return [];
        }

        $data = [];
        $files = $request->getUploadedFiles();
        foreach ($this->files as $key) {
            if (array_key_exists($key, $files)) {
                $data[$key] = $files[$key];
            }
        }
        return $data;
    }
}

<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

use Componenta\DI\Resolver\Parameter\Request\RequestMapperPipeline;
use Psr\Http\Message\ServerRequestInterface;

trait ExtractsRequestData
{
    /**
     * Request attributes to extract into the raw data array.
     *
     * Non-associative list of attribute names.
     * Use `[RequestMapperPipeline::WILDCARD]` (i.e. `['*']`) to extract all
     * attributes.
     *
     * @var list<string>
     */
    protected array $attributes = [];

    /**
     * Uploaded files to extract into the raw data array.
     *
     * Non-associative list of file keys from `$request->getUploadedFiles()`.
     * Use `[RequestMapperPipeline::WILDCARD]` (i.e. `['*']`) to extract all
     * uploaded files.
     *
     * @var list<string>
     */
    protected array $files = [];

    /**
     * Returns separately named shared sources so the mapper can detect
     * collisions before provenance is lost.
     *
     * @return array<string, array<string|int, mixed>>
     */
    protected function extractSharedSources(ServerRequestInterface $request): array
    {
        return [
            'request attributes' => $this->extractConfiguredRequestAttributes($request),
            'uploaded files' => $this->extractConfiguredUploadedFiles($request),
        ];
    }

    /**
     * Extracts shared request attributes and uploaded files.
     *
     * Kept as the convenience hook for custom mapper subclasses; unlike the
     * former array_merge implementation, it follows the configured conflict
     * policy and never silently overwrites a different value.
     *
     * @return array<string|int, mixed>
     */
    protected function extractSharedData(ServerRequestInterface $request): array
    {
        return $this->mergeRequestData($this->extractSharedSources($request));
    }

    /** @return array<string|int, mixed> */
    private function extractConfiguredRequestAttributes(ServerRequestInterface $request): array
    {
        if ($this->attributes === [RequestMapperPipeline::WILDCARD]) {
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

    /** @return array<string|int, mixed> */
    private function extractConfiguredUploadedFiles(ServerRequestInterface $request): array
    {
        if ($this->files === [RequestMapperPipeline::WILDCARD]) {
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

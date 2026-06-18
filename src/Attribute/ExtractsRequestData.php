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
     * Extracts shared request attributes and uploaded files.
     */
    protected function extractSharedData(ServerRequestInterface $request): array
    {
        $data = [];

        if ($this->attributes === [RequestMapperPipeline::WILDCARD]) {
            $data = $request->getAttributes();
        } elseif ($this->attributes !== []) {
            $attributes = $request->getAttributes();

            foreach ($this->attributes as $attribute) {
                if (array_key_exists($attribute, $attributes)) {
                    $data[$attribute] = $attributes[$attribute];
                }
            }
        }

        if ($this->files === [RequestMapperPipeline::WILDCARD]) {
            $data = array_merge($data, $request->getUploadedFiles());
        } elseif ($this->files !== []) {
            $files = $request->getUploadedFiles();

            foreach ($this->files as $key) {
                if (array_key_exists($key, $files)) {
                    $data[$key] = $files[$key];
                }
            }
        }

        return $data;
    }
}

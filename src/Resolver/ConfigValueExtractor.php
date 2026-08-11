<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver;

use ArrayAccess;
use Componenta\Config\Config as AppConfig;
use Componenta\Config\ConfigPath;
use Componenta\Config\DefaultValue;
use Componenta\DI\Attribute\Config;
use InvalidArgumentException;
use OutOfBoundsException;

/** Reads configuration values described by a {@see Config} attribute. */
final readonly class ConfigValueExtractor
{
    public const string MODE_IMPLICIT = 'implicit';
    public const string MODE_LITERAL = 'literal';
    public const string MODE_PATH = 'path';

    /**
     * @param array<string, mixed>|ArrayAccess<string, mixed> $configData
     * @throws InvalidArgumentException When traversal reaches a scalar value.
     * @throws OutOfBoundsException When a required key is missing.
     */
    public function extract(array|ArrayAccess $configData, Config $attribute, string $fallbackName): mixed
    {
        if ($attribute->path instanceof ConfigPath) {
            return $this->extractCompiled(
                $configData,
                self::MODE_PATH,
                $attribute->path->value,
                array_values($attribute->path->toArray()),
                $attribute->default,
                $fallbackName,
            );
        }

        return $this->extractCompiled(
            $configData,
            $attribute->path === null ? self::MODE_IMPLICIT : self::MODE_LITERAL,
            $attribute->path,
            [],
            $attribute->default,
            $fallbackName,
        );
    }

    /**
     * @param array<string, mixed>|ArrayAccess<string, mixed> $configData
     * @param list<string> $segments
     */
    public function extractCompiled(
        array|ArrayAccess $configData,
        string $mode,
        ?string $key,
        array $segments,
        mixed $default,
        string $fallbackName,
    ): mixed {
        if ($mode === self::MODE_PATH) {
            if ($key === null) {
                throw new InvalidArgumentException('Compiled config path requires a key.');
            }

            if ($configData instanceof AppConfig) {
                return $this->resolveFromAppConfig($configData, new ConfigPath($key), $default);
            }

            return $this->extractNested(
                $configData,
                $segments !== [] ? $segments : explode('.', $key),
                $default,
            );
        }

        if ($mode === self::MODE_LITERAL) {
            if ($key === null) {
                throw new InvalidArgumentException('Compiled config literal requires a key.');
            }

            return $this->extractLiteral($configData, $key, $default);
        }

        if ($mode === self::MODE_IMPLICIT) {
            return $this->extractLiteral($configData, $fallbackName, $default);
        }

        throw new InvalidArgumentException("Unsupported compiled config mode: $mode");
    }

    private function resolveFromAppConfig(AppConfig $config, ConfigPath $path, mixed $default): mixed
    {
        if ($config->has($path)) {
            return $config->get($path);
        }

        if ($default !== DefaultValue::None) {
            return $default;
        }

        throw new OutOfBoundsException("Undefined configuration key: {$path->value}");
    }

    /**
     * @param array<string, mixed>|ArrayAccess<string, mixed> $configData
     * @param list<string> $segments
     */
    private function extractNested(array|ArrayAccess $configData, array $segments, mixed $default): mixed
    {
        $entry = $configData;

        foreach ($segments as $i => $key) {
            if (!is_array($entry) && !$entry instanceof ArrayAccess) {
                $pathString = implode(' -> ', array_slice($segments, 0, $i));
                throw new InvalidArgumentException(
                    "The configuration value at path '$pathString' is not accessible",
                );
            }

            if (!$this->hasKey($entry, $key)) {
                if ($default !== DefaultValue::None) {
                    return $default;
                }

                $pathString = implode(' -> ', array_slice($segments, 0, $i + 1));
                throw new OutOfBoundsException("Undefined configuration key: $pathString");
            }

            $entry = $entry[$key];
        }

        return $entry;
    }

    /** @param array<string, mixed>|ArrayAccess<string, mixed> $configData */
    private function extractLiteral(array|ArrayAccess $configData, string $key, mixed $default): mixed
    {
        if (!$this->hasKey($configData, $key)) {
            if ($default !== DefaultValue::None) {
                return $default;
            }

            throw new OutOfBoundsException("Undefined configuration key: $key");
        }

        return $configData[$key];
    }

    /** @param array<string, mixed>|ArrayAccess<string, mixed> $configData */
    private function hasKey(array|ArrayAccess $configData, string $key): bool
    {
        if ($configData instanceof AppConfig) {
            return $configData->has($key);
        }

        if (is_array($configData)) {
            return array_key_exists($key, $configData);
        }

        return $configData->offsetExists($key);
    }
}

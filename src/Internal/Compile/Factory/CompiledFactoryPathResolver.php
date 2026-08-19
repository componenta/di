<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Compile\Factory;

use Componenta\DI\Exception\InvalidConfigurationException;

/**
 * Resolves compiled-factory shard paths before executable code is loaded.
 *
 * @internal
 */
final readonly class CompiledFactoryPathResolver
{
    public function __construct(
        private ?string $baseDirectory,
        private bool $trusted = false,
    ) {}

    public function resolve(string $file): string
    {
        if ($file === '') {
            throw new InvalidConfigurationException(
                'Compiled factory shard path must not be empty.',
            );
        }

        if ($this->trusted) {
            return $this->trustedPath($file);
        }

        if ($this->baseDirectory === null || $this->baseDirectory === '') {
            throw new InvalidConfigurationException(
                'Untrusted compiled factories require a base directory.',
            );
        }

        if (self::isAbsolutePath($file)) {
            throw new InvalidConfigurationException(sprintf(
                'Untrusted compiled factory shard "%s" must use a relative path.',
                $file,
            ));
        }

        $base = realpath($this->baseDirectory);
        if ($base === false || !is_dir($base)) {
            throw new InvalidConfigurationException(sprintf(
                'Compiled factory base directory "%s" does not exist.',
                $this->baseDirectory,
            ));
        }

        $target = realpath($base . DIRECTORY_SEPARATOR . $file);
        if ($target === false || !is_file($target)) {
            throw new InvalidConfigurationException(sprintf(
                'Compiled factory shard "%s" does not exist.',
                $file,
            ));
        }

        if (!self::isWithin($target, $base)) {
            throw new InvalidConfigurationException(sprintf(
                'Compiled factory shard "%s" resolves outside base directory "%s".',
                $file,
                $base,
            ));
        }

        return $target;
    }

    private function trustedPath(string $file): string
    {
        if ($this->baseDirectory !== null && !self::isAbsolutePath($file)) {
            return rtrim($this->baseDirectory, '/\\')
                . DIRECTORY_SEPARATOR
                . ltrim($file, '/\\');
        }

        return $file;
    }

    private static function isWithin(string $target, string $base): bool
    {
        $target = self::normalize($target);
        $base = rtrim(self::normalize($base), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR;

        if (DIRECTORY_SEPARATOR === '\\') {
            $target = strtolower($target);
            $base = strtolower($base);
        }

        return str_starts_with($target, $base);
    }

    private static function normalize(string $path): string
    {
        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    private static function isAbsolutePath(string $path): bool
    {
        return $path !== ''
            && ($path[0] === '/'
                || $path[0] === '\\'
                || (strlen($path) >= 2
                    && ctype_alpha($path[0])
                    && $path[1] === ':'));
    }
}

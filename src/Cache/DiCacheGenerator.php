<?php

declare(strict_types=1);

namespace Componenta\DI\Cache;

use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Export;

/** Default persistent-container cache writer. */
final readonly class DiCacheGenerator implements DiCacheGeneratorInterface
{
    public function generate(array $dependencies, string $path): void
    {
        $this->ensureDirectory(dirname($path));

        $cache = [
            'version' => ContainerBuilder::CACHE_VERSION,
            ConfigKey::DEPENDENCIES => ContainerBuilder::normalizeDependencies($dependencies),
        ];

        try {
            $exported = Export::pretty(
                $cache,
                ExportConfig::pretty()->withTrailingComma(),
            );
        } catch (\Throwable $e) {
            throw new InvalidConfigurationException(
                sprintf('Failed to serialise DI cache for "%s": %s', $path, $e->getMessage()),
                previous: $e,
            );
        }

        $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn {$exported};\n";
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));

        if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
            @unlink($tmp);
            throw new InvalidConfigurationException(
                sprintf('Failed to write DI cache temp file: %s', $tmp),
            );
        }

        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new InvalidConfigurationException(
                sprintf('Failed to commit DI cache file: %s', $path),
            );
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
    }

    private function ensureDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }

        if (!mkdir($dir, 0o755, recursive: true) && !is_dir($dir)) {
            throw new InvalidConfigurationException(
                sprintf('Failed to create DI cache directory: %s', $dir),
            );
        }
    }
}

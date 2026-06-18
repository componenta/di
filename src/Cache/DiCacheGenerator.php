<?php

declare(strict_types=1);

namespace Componenta\DI\Cache;

use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\VarExport\Export;
use Componenta\VarExport\Config\ExportConfig;

/**
 * Default {@see DiCacheGeneratorInterface} implementation.
 *
 * Serialises configuration via {@see Export::pretty()} (short-array
 * syntax, indented, trailing commas) and writes atomically through a
 * temporary file. Invalidates the OPcache entry for the target path
 * so the next request picks up the fresh contents without an FPM
 * restart in dev.
 */
final readonly class DiCacheGenerator implements DiCacheGeneratorInterface
{
    public function generate(array $config, string $path): void
    {
        $this->ensureDirectory(dirname($path));

        try {
            $exported = Export::pretty(
                $config,
                ExportConfig::pretty()->withTrailingComma(),
            );
        } catch (\Throwable $e) {
            throw new InvalidConfigurationException(
                sprintf('Failed to serialise DI cache for "%s": %s', $path, $e->getMessage()),
                previous: $e,
            );
        }

        $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn {$exported};\n";

        // Atomic write - the temp + rename pattern guarantees a concurrent
        // reader either sees the previous contents or the full new file,
        // never a partial write.
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));

        if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
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

        // OPcache holds the previous bytecode by inode/path; invalidate so
        // the next `require` re-reads the updated file.
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

<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Factory;

use RuntimeException;

/** Atomically writes and syntax-checks immutable generated factory shards. */
final readonly class CompiledFactoryShardWriter
{
    public function write(string $file, string $code): void
    {
        $directory = dirname($file);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Cannot create factory shard directory "%s".', $directory));
        }

        if (is_file($file)) {
            return;
        }

        $temporary = tempnam($directory, basename($file) . '.tmp.');
        if ($temporary === false) {
            throw new RuntimeException(sprintf('Cannot allocate a temporary file in "%s".', $directory));
        }

        try {
            if (file_put_contents($temporary, $code, LOCK_EX) === false) {
                throw new RuntimeException(sprintf('Cannot write generated factory shard "%s".', $temporary));
            }

            $this->lint($temporary);
            @chmod($temporary, 0644);

            if (!@rename($temporary, $file) && !is_file($file)) {
                throw new RuntimeException(sprintf('Cannot activate generated factory shard "%s".', $file));
            }

            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($file, true);
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function lint(string $file): void
    {
        if (!function_exists('proc_open')) {
            throw new RuntimeException('Generated factory shard cannot be validated because proc_open() is unavailable.');
        }

        $pipes = [];
        $process = @proc_open(
            [PHP_BINARY, '-n', '-d', 'memory_limit=-1', '-l', $file],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            options: ['bypass_shell' => true],
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start PHP syntax validation for a generated factory shard.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        if ($status !== 0) {
            $output = trim((is_string($stdout) ? $stdout : '') . "\n" . (is_string($stderr) ? $stderr : ''));
            throw new RuntimeException("Generated factory shard failed PHP compile validation:\n" . $output);
        }
    }
}

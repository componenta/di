<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Entry;

use RuntimeException;

/** Writes generated PHP atomically and activates it only after `php -l`. */
final class GeneratedEntryResolverWriter
{
    public function write(string $file, string $code): void
    {
        if ($file === '') {
            throw new RuntimeException('Generated entry resolver path cannot be empty.');
        }

        $directory = dirname($file);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf(
                'Cannot create generated entry resolver directory "%s".',
                $directory,
            ));
        }

        $temporary = tempnam($directory, basename($file) . '.tmp.');
        if ($temporary === false) {
            throw new RuntimeException(sprintf(
                'Cannot allocate a temporary file in "%s".',
                $directory,
            ));
        }

        try {
            if (file_put_contents($temporary, $code, LOCK_EX) === false) {
                throw new RuntimeException(sprintf(
                    'Cannot write generated entry resolver "%s".',
                    $temporary,
                ));
            }

            $this->lint($temporary);
            @chmod($temporary, 0644);

            if (!rename($temporary, $file)) {
                throw new RuntimeException(sprintf(
                    'Cannot atomically replace generated entry resolver "%s".',
                    $file,
                ));
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
            throw new RuntimeException(
                'Generated entry resolver cannot be validated because proc_open() is unavailable.',
            );
        }

        $pipes = [];
        $process = @proc_open(
            [PHP_BINARY, '-n', '-l', $file],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            options: ['bypass_shell' => true],
        );

        if (!is_resource($process)) {
            throw new RuntimeException(
                'Cannot start PHP syntax validation for the generated entry resolver.',
            );
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        if ($status !== 0) {
            $output = trim(implode("\n", array_filter([
                is_string($stdout) ? trim($stdout) : '',
                is_string($stderr) ? trim($stderr) : '',
            ])));

            throw new RuntimeException(sprintf(
                "Generated entry resolver failed PHP compile validation:%s%s",
                $output === '' ? '' : "\n",
                $output,
            ));
        }
    }
}

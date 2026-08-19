<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Factory;

use RuntimeException;

use function Componenta\DI\with_suppressed_warnings;

/** Atomically writes and syntax-checks immutable generated factory shards. */
final readonly class CompiledFactoryShardWriter
{
    public function write(string $file, string $code): void
    {
        $directory = dirname($file);

        if (!is_dir($directory)) {
            $created = with_suppressed_warnings(
                static fn(): bool => mkdir($directory, 0775, true),
            );

            if (!$created && !is_dir($directory)) {
                throw new RuntimeException(sprintf(
                    'Cannot create factory shard directory "%s".',
                    $directory,
                ));
            }
        }

        if (is_file($file)) {
            $this->assertExistingContents($file, $code);
            return;
        }

        $temporary = $this->writeTemporary($directory, basename($file), $code);

        try {
            $this->lint($temporary);
            with_suppressed_warnings(static fn(): bool => chmod($temporary, 0644));

            $committed = with_suppressed_warnings(
                static fn(): bool => rename($temporary, $file),
            );

            if (!$committed) {
                if (!is_file($file)) {
                    throw new RuntimeException(sprintf(
                        'Cannot activate generated factory shard "%s".',
                        $file,
                    ));
                }

                // Another writer may have published the same content-addressed
                // shard first. Reuse it only when its bytes are exactly the
                // artifact we intended to publish.
                $this->assertExistingContents($file, $code);
            }
        } finally {
            if (is_file($temporary)) {
                with_suppressed_warnings(static fn(): bool => unlink($temporary));
            }
        }
    }

    private function assertExistingContents(string $file, string $code): void
    {
        $existing = with_suppressed_warnings(
            static fn(): string|false => file_get_contents($file),
        );

        if ($existing === false) {
            throw new RuntimeException(sprintf(
                'Cannot read existing generated factory shard "%s".',
                $file,
            ));
        }

        if ($existing !== $code) {
            throw new RuntimeException(sprintf(
                'Generated factory shard "%s" already exists with unexpected contents.',
                $file,
            ));
        }

        $this->lint($file);
    }

    private function lint(string $file): void
    {
        if (!function_exists('proc_open')) {
            throw new RuntimeException('Generated factory shard cannot be validated because proc_open() is unavailable.');
        }

        $pipes = [];
        $process = with_suppressed_warnings(static function () use (&$pipes, $file) {
            return proc_open(
                [PHP_BINARY, '-n', '-d', 'memory_limit=-1', '-l', $file],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                options: ['bypass_shell' => true],
            );
        });
        $stdin = $pipes[0] ?? null;
        $stdoutPipe = $pipes[1] ?? null;
        $stderrPipe = $pipes[2] ?? null;

        if (!is_resource($process)
            || !is_resource($stdin)
            || !is_resource($stdoutPipe)
            || !is_resource($stderrPipe)
        ) {
            throw new RuntimeException('Cannot start PHP syntax validation for a generated factory shard.');
        }

        with_suppressed_warnings(static fn(): bool => fclose($stdin));
        $stdout = with_suppressed_warnings(static fn(): string|false => stream_get_contents($stdoutPipe));
        $stderr = with_suppressed_warnings(static fn(): string|false => stream_get_contents($stderrPipe));
        with_suppressed_warnings(static fn(): bool => fclose($stdoutPipe));
        with_suppressed_warnings(static fn(): bool => fclose($stderrPipe));
        $status = with_suppressed_warnings(static fn(): int => proc_close($process));

        if ($status !== 0) {
            $output = trim((is_string($stdout) ? $stdout : '') . "\n" . (is_string($stderr) ? $stderr : ''));
            throw new RuntimeException("Generated factory shard failed PHP compile validation:\n" . $output);
        }
    }

    private function writeTemporary(string $directory, string $baseName, string $code): string
    {
        for ($attempt = 0; $attempt < 8; ++$attempt) {
            $temporary = $directory
                . DIRECTORY_SEPARATOR
                . $baseName
                . '.tmp.'
                . bin2hex(random_bytes(8));
            $handle = with_suppressed_warnings(
                static fn() => fopen($temporary, 'xb'),
            );

            if (!is_resource($handle)) {
                continue;
            }

            try {
                $length = strlen($code);
                $offset = 0;

                while ($offset < $length) {
                    $written = with_suppressed_warnings(
                        static fn(): int|false => fwrite($handle, substr($code, $offset)),
                    );

                    if ($written === false || $written === 0) {
                        throw new RuntimeException(sprintf(
                            'Cannot write generated factory shard "%s".',
                            $temporary,
                        ));
                    }

                    $offset += $written;
                }

                if (!with_suppressed_warnings(static fn(): bool => fflush($handle))) {
                    throw new RuntimeException(sprintf(
                        'Cannot flush generated factory shard "%s".',
                        $temporary,
                    ));
                }
            } catch (\Throwable $e) {
                with_suppressed_warnings(static fn(): bool => fclose($handle));
                with_suppressed_warnings(static fn(): bool => unlink($temporary));

                throw $e;
            }

            with_suppressed_warnings(static fn(): bool => fclose($handle));

            return $temporary;
        }

        throw new RuntimeException(sprintf(
            'Cannot allocate a temporary file in "%s".',
            $directory,
        ));
    }
}

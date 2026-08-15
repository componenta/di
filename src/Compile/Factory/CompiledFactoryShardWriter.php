<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Factory;

use Componenta\DI\Internal\WarningGuard;
use RuntimeException;

/** Atomically writes and syntax-checks immutable generated factory shards. */
final readonly class CompiledFactoryShardWriter
{
    public function write(string $file, string $code): void
    {
        $directory = dirname($file);

        if (!is_dir($directory)) {
            $created = WarningGuard::run(
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
            WarningGuard::run(static fn(): bool => chmod($temporary, 0644));

            $committed = WarningGuard::run(
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
                WarningGuard::run(static fn(): bool => unlink($temporary));
            }
        }
    }

    private function assertExistingContents(string $file, string $code): void
    {
        $existing = WarningGuard::run(
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
        $process = WarningGuard::run(static function () use (&$pipes, $file) {
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

        WarningGuard::run(static fn(): bool => fclose($stdin));
        $stdout = WarningGuard::run(static fn(): string|false => stream_get_contents($stdoutPipe));
        $stderr = WarningGuard::run(static fn(): string|false => stream_get_contents($stderrPipe));
        WarningGuard::run(static fn(): bool => fclose($stdoutPipe));
        WarningGuard::run(static fn(): bool => fclose($stderrPipe));
        $status = WarningGuard::run(static fn(): int => proc_close($process));

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
            $handle = WarningGuard::run(
                static fn() => fopen($temporary, 'xb'),
            );

            if (!is_resource($handle)) {
                continue;
            }

            try {
                $length = strlen($code);
                $offset = 0;

                while ($offset < $length) {
                    $written = WarningGuard::run(
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

                if (!WarningGuard::run(static fn(): bool => fflush($handle))) {
                    throw new RuntimeException(sprintf(
                        'Cannot flush generated factory shard "%s".',
                        $temporary,
                    ));
                }
            } catch (\Throwable $e) {
                WarningGuard::run(static fn(): bool => fclose($handle));
                WarningGuard::run(static fn(): bool => unlink($temporary));

                throw $e;
            }

            WarningGuard::run(static fn(): bool => fclose($handle));

            return $temporary;
        }

        throw new RuntimeException(sprintf(
            'Cannot allocate a temporary file in "%s".',
            $directory,
        ));
    }
}

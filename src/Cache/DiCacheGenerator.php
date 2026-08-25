<?php

declare(strict_types=1);

namespace Componenta\DI\Cache;

use Componenta\DI\Compile\Definition\DefinitionCompiler;
use Componenta\DI\Compile\Definition\DefinitionCompilerInterface;
use Componenta\DI\Compile\Definition\GeneratedDefinitionCode;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Internal\WarningGuard;
use Componenta\DI\Resolver\Entry\FactorySpecificationValidator;
use Componenta\VarExport\Config\ClosureExportPolicy;
use Componenta\VarExport\Config\ClosureUseMode;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Config\SourcePathPolicy;
use Throwable;

/** Default persistent-container cache writer. */
final readonly class DiCacheGenerator implements DiCacheGeneratorInterface
{
    private DefinitionCompilerInterface $definitionCompiler;

    public function __construct(?DefinitionCompilerInterface $definitionCompiler = null)
    {
        $this->definitionCompiler = $definitionCompiler ?? DefinitionCompiler::createDefault();
    }

    public function generate(array $dependencies, string $path): void
    {
        $this->ensureDirectory(dirname($path));
        $dependencies = ContainerBuilder::normalizeDependencies($dependencies);
        $dependencies = $this->definitionCompiler->compile($dependencies);
        $this->assertCompiledFactories($dependencies);
        $cache = [
            'version' => ContainerBuilder::CACHE_VERSION,
            ConfigKey::DEPENDENCIES => $dependencies,
        ];

        try {
            $config = ExportConfig::pretty()
                ->withTrailingComma()
                ->withClosureUseMode(ClosureUseMode::Inline)
                ->withClosureExportPolicy(ClosureExportPolicy::PortableExpression)
                ->withSourcePathPolicy(SourcePathPolicy::Reject);
            $exported = (new DiCacheGraphExporter(
                $config,
                $this->trustedGeneratedCode($dependencies),
            ))->export($cache);
        } catch (Throwable $e) {
            throw new InvalidConfigurationException(
                sprintf('Failed to serialise DI cache for "%s": %s', $path, $e->getMessage()),
                previous: $e,
            );
        }

        $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn {$exported};\n";
        $tmp = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(8));

        try {
            $this->writeAll($tmp, $contents);
            $this->lint($tmp);

            $wasOpcodeCached = is_file($path)
                && function_exists('opcache_is_script_cached')
                && WarningGuard::run(static fn(): bool => opcache_is_script_cached($path));

            if (
                $wasOpcodeCached
                && (!function_exists('opcache_invalidate')
                    || !WarningGuard::run(static fn(): bool => opcache_invalidate($path, true)))
            ) {
                throw new InvalidConfigurationException(sprintf(
                    'DI cache "%s" cannot be replaced because its previous OPcache entry could not be invalidated.',
                    $path,
                ));
            }

            $committed = WarningGuard::run(static fn(): bool => rename($tmp, $path));
            if (!$committed) {
                throw new InvalidConfigurationException(sprintf(
                    'Failed to commit DI cache file: %s',
                    $path,
                ));
            }
        } catch (Throwable $e) {
            if (is_file($tmp)) {
                WarningGuard::run(static fn(): bool => unlink($tmp));
            }

            if ($e instanceof InvalidConfigurationException) {
                throw $e;
            }

            throw new InvalidConfigurationException(
                sprintf('Failed to write DI cache "%s": %s', $path, $e->getMessage()),
                previous: $e,
            );
        }

        if (function_exists('opcache_invalidate')) {
            WarningGuard::run(static fn(): bool => opcache_invalidate($path, true));
        }
    }

    /** @param array<string, mixed> $dependencies */
    private function assertCompiledFactories(array $dependencies): void
    {
        $factories = $dependencies[ConfigKey::FACTORIES] ?? [];
        if (!is_array($factories)) {
            throw new InvalidConfigurationException('Factories must be an array after definition compilation.');
        }

        foreach ($factories as $id => $factory) {
            if ($factory instanceof GeneratedDefinitionCode) {
                continue;
            }

            FactorySpecificationValidator::assertValid($id, $factory);
        }
    }

    /** @param array<string, mixed> $dependencies @return array<int, true> */
    private function trustedGeneratedCode(array $dependencies): array
    {
        $trusted = [];
        $factories = $dependencies[ConfigKey::FACTORIES] ?? [];
        if (!is_array($factories)) {
            return $trusted;
        }

        foreach ($factories as $factory) {
            if ($factory instanceof GeneratedDefinitionCode) {
                $trusted[spl_object_id($factory)] = true;
            }
        }

        return $trusted;
    }

    private function ensureDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }

        $created = WarningGuard::run(static fn(): bool => mkdir($dir, 0o755, recursive: true));
        if (!$created && !is_dir($dir)) {
            throw new InvalidConfigurationException(sprintf(
                'Failed to create DI cache directory: %s',
                $dir,
            ));
        }
    }

    private function writeAll(string $path, string $contents): void
    {
        $stream = WarningGuard::run(static fn() => fopen($path, 'xb'));
        if (!is_resource($stream)) {
            throw new InvalidConfigurationException(sprintf(
                'Failed to create DI cache temp file: %s',
                $path,
            ));
        }

        try {
            $offset = 0;
            $length = strlen($contents);
            while ($offset < $length) {
                $written = WarningGuard::run(
                    static fn(): int|false => fwrite($stream, substr($contents, $offset)),
                );
                if ($written === false || $written === 0) {
                    throw new InvalidConfigurationException(sprintf(
                        'Failed to write DI cache temp file: %s',
                        $path,
                    ));
                }

                $offset += $written;
            }

            if (!WarningGuard::run(static fn(): bool => fflush($stream))) {
                throw new InvalidConfigurationException(sprintf(
                    'Failed to flush DI cache temp file: %s',
                    $path,
                ));
            }

            if (function_exists('fsync') && !WarningGuard::run(static fn(): bool => fsync($stream))) {
                throw new InvalidConfigurationException(sprintf(
                    'Failed to synchronise DI cache temp file: %s',
                    $path,
                ));
            }
        } finally {
            WarningGuard::run(static fn(): bool => fclose($stream));
        }
    }

    private function lint(string $path): void
    {
        if (!function_exists('proc_open')) {
            throw new InvalidConfigurationException(
                'DI cache cannot be syntax-validated because proc_open() is unavailable.',
            );
        }

        $pipes = [];
        $process = WarningGuard::run(static function () use (&$pipes, $path) {
            return proc_open(
                [PHP_BINARY, '-n', '-d', 'memory_limit=-1', '-l', $path],
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                options: ['bypass_shell' => true],
            );
        });

        $stdin = $pipes[0] ?? null;
        $stdoutPipe = $pipes[1] ?? null;
        $stderrPipe = $pipes[2] ?? null;
        if (
            !is_resource($process)
            || !is_resource($stdin)
            || !is_resource($stdoutPipe)
            || !is_resource($stderrPipe)
        ) {
            throw new InvalidConfigurationException(
                'Cannot start PHP syntax validation for a DI cache artifact.',
            );
        }

        WarningGuard::run(static fn(): bool => fclose($stdin));
        $stdout = WarningGuard::run(static fn(): string|false => stream_get_contents($stdoutPipe));
        $stderr = WarningGuard::run(static fn(): string|false => stream_get_contents($stderrPipe));
        WarningGuard::run(static fn(): bool => fclose($stdoutPipe));
        WarningGuard::run(static fn(): bool => fclose($stderrPipe));
        $status = WarningGuard::run(static fn(): int => proc_close($process));

        if ($status === 0) {
            return;
        }

        $output = trim(
            (is_string($stdout) ? $stdout : '')
            . "\n"
            . (is_string($stderr) ? $stderr : ''),
        );

        throw new InvalidConfigurationException(
            "DI cache failed PHP syntax validation:\n" . $output,
        );
    }
}

<?php

declare(strict_types=1);

namespace Componenta\DI\Cache;

use Componenta\DI\Compile\Definition\DefinitionCompiler;
use Componenta\DI\Compile\Definition\DefinitionCompilerInterface;
use Componenta\DI\Compile\Definition\GeneratedDefinitionCode;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\CompilationException;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Internal\Cache\DiCacheGraphExporter;
use Componenta\DI\Internal\Resolver\Entry\FactorySpecificationValidator;
use Componenta\VarExport\Config\ExportConfig;
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
        try {
            $this->generateCache($dependencies, $path);
        } catch (InvalidConfigurationException|CompilationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw CompilationException::forArtifact($path, $e);
        }
    }

    /** @param array<string,mixed> $dependencies */
    private function generateCache(array $dependencies, string $path): void
    {
        $directory = dirname($path);
        $this->ensureDirectory($directory);

        $dependencies = ContainerBuilder::normalizeDependencies($dependencies);
        $dependencies = $this->definitionCompiler->compile($dependencies);
        $dependencies = $this->normalizeCompiledDependencies($dependencies);
        $this->assertCompiledFactories($dependencies);
        $cache = [
            'version' => ContainerBuilder::CACHE_VERSION,
            ConfigKey::DEPENDENCIES => $dependencies,
        ];

        try {
            $config = ExportConfig::pretty()->withTrailingComma();
            $exported = (new DiCacheGraphExporter(
                $config,
                $this->trustedGeneratedCode($dependencies),
            ))->export($cache);
        } catch (InvalidConfigurationException|CompilationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new CompilationException(
                sprintf('Failed to serialise DI cache for "%s": %s', $path, $e->getMessage()),
                previous: $e,
            );
        }

        $wasOpcodeCached = is_file($path)
            && function_exists('opcache_is_script_cached')
            && @\opcache_is_script_cached($path);
        $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn {$exported};\n";
        $temporary = $this->writeTemporary($directory, basename($path), $contents);

        try {
            $this->lint($temporary);

            if ($wasOpcodeCached
                && (!function_exists('opcache_invalidate') || !@\opcache_invalidate($path, true))
            ) {
                throw new CompilationException(sprintf(
                    'DI cache "%s" cannot be replaced because its previous OPcache entry could not be invalidated.',
                    $path,
                ));
            }

            $committed = @rename($temporary, $path);

            if (!$committed) {
                throw new CompilationException(sprintf('Failed to commit DI cache file: %s', $path));
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }

        // The old cached opcode, when present, was invalidated before commit so
        // activation cannot fail after mutating the live artifact. Refreshing
        // the new path is best-effort because it may not be cached yet.
        if (function_exists('opcache_invalidate')) {
            @\opcache_invalidate($path, true);
        }
    }

    /**
     * GeneratedDefinitionCode is executable cache output rather than an ordinary
     * factory specification. Replace only those values with a valid placeholder
     * while canonical normalization validates their ids, alias collisions,
     * protected ids and every non-factory section, then restore the generated
     * expressions unchanged.
     *
     * @param array<string,mixed> $dependencies
     * @return array<string,mixed>
     */
    private function normalizeCompiledDependencies(array $dependencies): array
    {
        $factories = $dependencies[ConfigKey::FACTORIES] ?? [];
        if (!is_array($factories)) {
            throw new InvalidConfigurationException('Factories must be an array after definition compilation.');
        }

        /** @var array<array-key,GeneratedDefinitionCode> $generated */
        $generated = [];
        $validationFactories = $factories;
        foreach ($factories as $id => $factory) {
            if (!$factory instanceof GeneratedDefinitionCode) {
                continue;
            }

            $generated[$id] = $factory;
            $validationFactories[$id] = static fn(): null => null;
        }

        $validation = $dependencies;
        $validation[ConfigKey::FACTORIES] = $validationFactories;
        $normalized = ContainerBuilder::normalizeDependencies($validation);
        $normalizedFactories = $normalized[ConfigKey::FACTORIES] ?? [];
        if (!is_array($normalizedFactories)) {
            throw new InvalidConfigurationException('Normalized factories must be an array.');
        }

        foreach ($generated as $id => $factory) {
            $normalizedFactories[$id] = $factory;
        }
        $normalized[ConfigKey::FACTORIES] = $normalizedFactories;

        return $normalized;
    }

    /** @param array<string,mixed> $dependencies */
    private function assertCompiledFactories(array $dependencies): void
    {
        $factories = $dependencies[ConfigKey::FACTORIES] ?? [];
        if (!is_array($factories)) {
            throw new InvalidConfigurationException('Factories must be an array after definition compilation.');
        }

        foreach ($factories as $id => $factory) {
            if (!is_string($id) || $id === '') {
                throw new InvalidConfigurationException('Factory ids must remain non-empty strings after compilation.');
            }
            if ($factory instanceof GeneratedDefinitionCode) {
                continue;
            }
            FactorySpecificationValidator::assertValid($id, $factory);
        }
    }

    /**
     * @param array<string,mixed> $dependencies
     * @return array<int,true>
     */
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

        if (file_exists($dir)) {
            throw new CompilationException(sprintf(
                'DI cache parent path is not a directory: %s',
                $dir,
            ));
        }

        $created = @mkdir($dir, 0o755, recursive: true);

        if (!$created && !is_dir($dir)) {
            throw new CompilationException(sprintf('Failed to create DI cache directory: %s', $dir));
        }
    }

    private function writeTemporary(string $directory, string $baseName, string $contents): string
    {
        for ($attempt = 0; $attempt < 8; ++$attempt) {
            $temporary = $directory
                . DIRECTORY_SEPARATOR
                . $baseName
                . '.tmp.'
                . bin2hex(random_bytes(8));
            $handle = @fopen($temporary, 'xb');

            if (!is_resource($handle)) {
                continue;
            }

            try {
                $length = strlen($contents);
                $offset = 0;

                while ($offset < $length) {
                    $written = @fwrite($handle, substr($contents, $offset));

                    if ($written === false || $written === 0) {
                        throw new CompilationException(sprintf(
                            'Failed to write complete DI cache temp file: %s',
                            $temporary,
                        ));
                    }

                    $offset += $written;
                }

                if (!@fflush($handle)) {
                    throw new CompilationException(sprintf(
                        'Failed to flush DI cache temp file: %s',
                        $temporary,
                    ));
                }
            } catch (Throwable $e) {
                @fclose($handle);
                @unlink($temporary);
                throw $e;
            }

            @fclose($handle);
            return $temporary;
        }

        throw new CompilationException(sprintf(
            'Failed to allocate a DI cache temp file in: %s',
            $directory,
        ));
    }

    private function lint(string $file): void
    {
        if (!function_exists('proc_open')) {
            throw new CompilationException(
                'DI cache cannot be validated because proc_open() is unavailable.',
            );
        }

        $pipes = [];
        $process = @proc_open(
            [PHP_BINARY, '-n', '-d', 'memory_limit=-1', '-l', $file],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            options: ['bypass_shell' => true],
        );
        $stdin = $pipes[0] ?? null;
        $stdoutPipe = $pipes[1] ?? null;
        $stderrPipe = $pipes[2] ?? null;

        if (!is_resource($process)
            || !is_resource($stdin)
            || !is_resource($stdoutPipe)
            || !is_resource($stderrPipe)
        ) {
            throw new CompilationException('Cannot start PHP syntax validation for a DI cache artifact.');
        }

        @fclose($stdin);
        $stdout = @stream_get_contents($stdoutPipe);
        $stderr = @stream_get_contents($stderrPipe);
        @fclose($stdoutPipe);
        @fclose($stderrPipe);
        $status = @proc_close($process);

        if ($status !== 0) {
            $output = trim((is_string($stdout) ? $stdout : '') . "\n" . (is_string($stderr) ? $stderr : ''));
            throw new CompilationException(
                "DI cache failed PHP compile validation:\n" . $output,
            );
        }
    }
}

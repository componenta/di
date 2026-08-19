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
use Componenta\DI\Internal\Resolver\Entry\FactorySpecificationValidator;
use Componenta\VarExport\Config\ExportConfig;
use Throwable;

use function Componenta\DI\with_suppressed_warnings;

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
        $this->ensureDirectory(dirname($path));

        $dependencies = ContainerBuilder::normalizeDependencies($dependencies);
        $dependencies = $this->definitionCompiler->compile($dependencies);
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
            && with_suppressed_warnings(
                static fn(): bool => opcache_is_script_cached($path),
            );
        $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn {$exported};\n";
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        $written = with_suppressed_warnings(
            static fn(): int|false => file_put_contents($tmp, $contents, LOCK_EX),
        );

        if ($written === false) {
            with_suppressed_warnings(static fn(): bool => unlink($tmp));
            throw new CompilationException(sprintf('Failed to write DI cache temp file: %s', $tmp));
        }

        $committed = with_suppressed_warnings(
            static fn(): bool => rename($tmp, $path),
        );

        if (!$committed) {
            with_suppressed_warnings(static fn(): bool => unlink($tmp));
            throw new CompilationException(sprintf('Failed to commit DI cache file: %s', $path));
        }

        if (function_exists('opcache_invalidate')) {
            $invalidated = with_suppressed_warnings(
                static fn(): bool => opcache_invalidate($path, true),
            );

            if ($wasOpcodeCached && !$invalidated) {
                throw new CompilationException(sprintf(
                    'DI cache "%s" was replaced, but its previous OPcache entry could not be invalidated.',
                    $path,
                ));
            }
        }
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

        $created = with_suppressed_warnings(
            static fn(): bool => mkdir($dir, 0o755, recursive: true),
        );

        if (!$created && !is_dir($dir)) {
            throw new CompilationException(sprintf('Failed to create DI cache directory: %s', $dir));
        }
    }
}

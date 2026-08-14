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
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\VarExporter;

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
            $config = ExportConfig::pretty()->withTrailingComma();
            $exported = (new VarExporter(
                $config,
                objectExporter: new DiCacheObjectExporter(
                    $config,
                    $this->trustedGeneratedCode($dependencies),
                ),
            ))->export($cache);
        } catch (\Throwable $e) {
            throw new InvalidConfigurationException(
                sprintf('Failed to serialise DI cache for "%s": %s', $path, $e->getMessage()),
                previous: $e,
            );
        }

        $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn {$exported};\n";
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        $written = WarningGuard::run(
            static fn(): int|false => file_put_contents($tmp, $contents, LOCK_EX),
        );

        if ($written === false) {
            WarningGuard::run(static fn(): bool => unlink($tmp));
            throw new InvalidConfigurationException(
                sprintf('Failed to write DI cache temp file: %s', $tmp),
            );
        }

        $committed = WarningGuard::run(
            static fn(): bool => rename($tmp, $path),
        );

        if (!$committed) {
            WarningGuard::run(static fn(): bool => unlink($tmp));
            throw new InvalidConfigurationException(
                sprintf('Failed to commit DI cache file: %s', $path),
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

    /**
     * @param array<string, mixed> $dependencies
     * @return array<int, true>
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

        $created = WarningGuard::run(
            static fn(): bool => mkdir($dir, 0o755, recursive: true),
        );

        if (!$created && !is_dir($dir)) {
            throw new InvalidConfigurationException(
                sprintf('Failed to create DI cache directory: %s', $dir),
            );
        }
    }
}

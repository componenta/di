<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\Config\Config;
use Componenta\DI\Compile\Factory\CompiledFactoryDefinition;
use Componenta\DI\Compile\Factory\CompiledFactoryShardCompiler;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Object\ObjectPipeline;

final class AuditSharedRootEntry {}

final class AuditDifferentRootEntry {}

/**
 * @param class-string $class
 * @param class-string $target
 */
function auditRootShardSource(string $class, string $target): string
{
    $separator = strrpos($class, '\\');
    if ($separator === false) {
        throw new \LogicException('Generated shard class must be namespaced.');
    }

    $namespace = substr($class, 0, $separator);
    $short = substr($class, $separator + 1);

    return sprintf(
        <<<'PHP'
<?php

declare(strict_types=1);

namespace %s;

final class %s
{
    public const int FORMAT_VERSION = %d;
    public const array ENTRIES = ['createEntry' => \%s::class];

    public function __construct(
        private readonly \%s $objects,
    ) {}

    public function createEntry(array $params = []): object
    {
        return $this->objects->create(\%s::class, $params);
    }
}

return %s::class;
PHP,
        $namespace,
        $short,
        CompiledFactoryShardCompiler::FORMAT_VERSION,
        $target,
        ObjectPipeline::class,
        $target,
        $short,
    );
}

function writeAuditRootShard(string $directory, string $source): string
{
    if (!is_dir($directory)) {
        mkdir($directory, 0o775, true);
    }

    $file = CompiledFactoryShardCompiler::FILE_PREFIX
        . substr(hash('sha256', $source), 0, 32)
        . '.php';
    file_put_contents($directory . DIRECTORY_SEPARATOR . $file, $source);

    return $file;
}

function cleanupAuditRootShardDirectory(string $directory): void
{
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    if (is_dir($directory)) {
        @rmdir($directory);
    }
}

test('identical content-addressed shards can be reused across cache roots', function (): void {
    $root = sys_get_temp_dir()
        . '/componenta-di-shared-roots-'
        . bin2hex(random_bytes(5));
    $firstBase = $root . '/first';
    $secondBase = $root . '/second';
    $class = 'Componenta\\DI\\Tests\\Generated\\SharedRoot'
        . bin2hex(random_bytes(5))
        . '\\Shard';
    $source = auditRootShardSource($class, AuditSharedRootEntry::class);
    $file = writeAuditRootShard($firstBase, $source);
    writeAuditRootShard($secondBase, $source);
    $definition = (new CompiledFactoryDefinition($file, $class, 'createEntry'))->encode();
    $cache = [
        'version' => ContainerBuilder::CACHE_VERSION,
        ConfigKey::DEPENDENCIES => [
            ConfigKey::FACTORIES => ['entry' => $definition],
        ],
    ];

    try {
        $first = ContainerBuilder::configureFromCache(new Config([]), $cache, $firstBase)->build();
        $second = ContainerBuilder::configureFromCache(new Config([]), $cache, $secondBase)->build();

        expect($first->make('entry'))->toBeInstanceOf(AuditSharedRootEntry::class)
            ->and($second->make('entry'))->toBeInstanceOf(AuditSharedRootEntry::class);
    } finally {
        cleanupAuditRootShardDirectory($firstBase);
        cleanupAuditRootShardDirectory($secondBase);
        @rmdir($root);
    }
});

test('different content-addressed shards cannot reuse the same generated class across cache roots', function (): void {
    $root = sys_get_temp_dir()
        . '/componenta-di-different-roots-'
        . bin2hex(random_bytes(5));
    $firstBase = $root . '/first';
    $secondBase = $root . '/second';
    $class = 'Componenta\\DI\\Tests\\Generated\\DifferentRoot'
        . bin2hex(random_bytes(5))
        . '\\Shard';
    $firstSource = auditRootShardSource($class, AuditSharedRootEntry::class);
    $secondSource = auditRootShardSource($class, AuditDifferentRootEntry::class);
    $firstFile = writeAuditRootShard($firstBase, $firstSource);
    $secondFile = writeAuditRootShard($secondBase, $secondSource);
    $firstDefinition = (new CompiledFactoryDefinition($firstFile, $class, 'createEntry'))->encode();
    $secondDefinition = (new CompiledFactoryDefinition($secondFile, $class, 'createEntry'))->encode();
    $firstCache = [
        'version' => ContainerBuilder::CACHE_VERSION,
        ConfigKey::DEPENDENCIES => [
            ConfigKey::FACTORIES => ['entry' => $firstDefinition],
        ],
    ];
    $secondCache = [
        'version' => ContainerBuilder::CACHE_VERSION,
        ConfigKey::DEPENDENCIES => [
            ConfigKey::FACTORIES => ['entry' => $secondDefinition],
        ],
    ];

    try {
        $first = ContainerBuilder::configureFromCache(new Config([]), $firstCache, $firstBase)->build();
        $second = ContainerBuilder::configureFromCache(new Config([]), $secondCache, $secondBase)->build();

        expect($first->make('entry'))->toBeInstanceOf(AuditSharedRootEntry::class)
            ->and(fn() => $second->make('entry'))
            ->toThrow(InvalidConfigurationException::class, 'unexpected file');
    } finally {
        cleanupAuditRootShardDirectory($firstBase);
        cleanupAuditRootShardDirectory($secondBase);
        @rmdir($root);
    }
});

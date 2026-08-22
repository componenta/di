<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\DI\Compile\Factory\CompiledFactoryDefinition;
use Componenta\DI\Compile\Factory\CompiledFactoryShardCompiler;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Object\ObjectPipeline;

final class AuditStaleAotEntry {}

final class AuditCrosswiredAotEntry {}

/**
 * @param array<string,string> $entries
 * @param class-string $target
 * @return array{file:string,class:class-string}
 */
function writeAuditStaleShard(
    int $format,
    array $entries,
    string $target = AuditStaleAotEntry::class,
): array {
    $suffix = bin2hex(random_bytes(5));
    $namespace = 'Componenta\\DI\\Tests\\Generated\\Stale' . $suffix;
    $class = $namespace . '\\Shard';
    $file = sys_get_temp_dir() . '/componenta-di-stale-shard-' . $suffix . '.php';
    $code = sprintf(
        <<<'PHP'
<?php

declare(strict_types=1);

namespace %s;

final class Shard
{
    public const int FORMAT_VERSION = %d;
    public const array ENTRIES = %s;

    public function __construct(
        private readonly \%s $objects,
    ) {}

    public function createEntry(array $params = []): object
    {
        return $this->objects->create(\%s::class, $params);
    }
}

return Shard::class;
PHP,
        $namespace,
        $format,
        var_export($entries, true),
        ObjectPipeline::class,
        $target,
    );
    file_put_contents($file, $code);

    /** @var class-string $class */
    return ['file' => $file, 'class' => $class];
}

test('compiled shards reject obsolete ABI versions before activation', function (): void {
    $artifact = writeAuditStaleShard(
        CompiledFactoryShardCompiler::FORMAT_VERSION - 1,
        ['createEntry' => AuditStaleAotEntry::class],
    );

    try {
        $container = (new ContainerBuilder())
            ->addDefinition(
                AuditStaleAotEntry::class,
                new CompiledFactoryDefinition($artifact['file'], $artifact['class'], 'createEntry'),
            )
            ->build();

        expect(fn() => $container->make(AuditStaleAotEntry::class))
            ->toThrow(InvalidConfigurationException::class, 'unsupported format');
    } finally {
        @unlink($artifact['file']);
    }
});

test('compiled shards reject methods missing from entry metadata', function (): void {
    $artifact = writeAuditStaleShard(
        CompiledFactoryShardCompiler::FORMAT_VERSION,
        [],
    );

    try {
        $container = (new ContainerBuilder())
            ->addDefinition(
                AuditStaleAotEntry::class,
                new CompiledFactoryDefinition($artifact['file'], $artifact['class'], 'createEntry'),
            )
            ->build();

        expect(fn() => $container->make(AuditStaleAotEntry::class))
            ->toThrow(InvalidConfigurationException::class, 'stale for method');
    } finally {
        @unlink($artifact['file']);
    }
});

test('compiled shards reject entry metadata for unavailable target classes', function (): void {
    $artifact = writeAuditStaleShard(
        CompiledFactoryShardCompiler::FORMAT_VERSION,
        ['createEntry' => 'Componenta\\DI\\Tests\\V5\\MissingCompiledEntry'],
    );

    try {
        $container = (new ContainerBuilder())
            ->addDefinition(
                AuditStaleAotEntry::class,
                new CompiledFactoryDefinition($artifact['file'], $artifact['class'], 'createEntry'),
            )
            ->build();

        expect(fn() => $container->make(AuditStaleAotEntry::class))
            ->toThrow(InvalidConfigurationException::class, 'stale for method');
    } finally {
        @unlink($artifact['file']);
    }
});

test('compiled shard metadata cannot reuse a class preloaded from another file', function (): void {
    $declared = writeAuditStaleShard(
        CompiledFactoryShardCompiler::FORMAT_VERSION,
        ['createEntry' => AuditStaleAotEntry::class],
    );
    $foreign = writeAuditStaleShard(
        CompiledFactoryShardCompiler::FORMAT_VERSION,
        ['createEntry' => AuditCrosswiredAotEntry::class],
        AuditCrosswiredAotEntry::class,
    );

    try {
        require $foreign['file'];

        $container = (new ContainerBuilder())
            ->addDefinition(
                'crosswired.preloaded',
                new CompiledFactoryDefinition($declared['file'], $foreign['class'], 'createEntry'),
            )
            ->build();

        expect(fn() => $container->make('crosswired.preloaded'))
            ->toThrow(InvalidConfigurationException::class, 'unexpected file');
    } finally {
        @unlink($declared['file']);
        @unlink($foreign['file']);
    }
});

test('equivalent shard copies may satisfy a preloaded generated class', function (): void {
    $artifact = writeAuditStaleShard(
        CompiledFactoryShardCompiler::FORMAT_VERSION,
        ['createEntry' => AuditStaleAotEntry::class],
    );
    $copy = sys_get_temp_dir()
        . '/componenta-di-equivalent-shard-'
        . bin2hex(random_bytes(5))
        . '.php';
    copy($artifact['file'], $copy);

    try {
        require $artifact['file'];

        $container = (new ContainerBuilder())
            ->addDefinition(
                'equivalent.copy',
                new CompiledFactoryDefinition($copy, $artifact['class'], 'createEntry'),
            )
            ->build();

        expect($container->make('equivalent.copy'))->toBeInstanceOf(AuditStaleAotEntry::class);
    } finally {
        @unlink($artifact['file']);
        @unlink($copy);
    }
});

test('one compiled shard file cannot be cached under two generated classes', function (): void {
    $declared = writeAuditStaleShard(
        CompiledFactoryShardCompiler::FORMAT_VERSION,
        ['createEntry' => AuditStaleAotEntry::class],
    );
    $foreign = writeAuditStaleShard(
        CompiledFactoryShardCompiler::FORMAT_VERSION,
        ['createEntry' => AuditCrosswiredAotEntry::class],
        AuditCrosswiredAotEntry::class,
    );

    try {
        require $foreign['file'];

        $container = (new ContainerBuilder())
            ->addDefinition(
                'crosswired.correct',
                new CompiledFactoryDefinition($declared['file'], $declared['class'], 'createEntry'),
            )
            ->addDefinition(
                'crosswired.cached',
                new CompiledFactoryDefinition($declared['file'], $foreign['class'], 'createEntry'),
            )
            ->build();

        expect($container->make('crosswired.correct'))->toBeInstanceOf(AuditStaleAotEntry::class)
            ->and(fn() => $container->make('crosswired.cached'))
            ->toThrow(InvalidConfigurationException::class, 'already loaded as');
    } finally {
        @unlink($declared['file']);
        @unlink($foreign['file']);
    }
});

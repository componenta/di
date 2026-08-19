<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Compile\Factory\CompiledFactoryDefinition;
use Componenta\DI\Compile\Factory\CompiledFactoryShardCompiler;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Object\ObjectPipeline;
use Componenta\DI\Resolver\Parameter\ParametersResolver;

use function Componenta\DI\compiled_factory_pipeline_fingerprint;

final class AuditStaleAotEntry {}

/**
 * @param array<string,string> $entries
 * @return array{file:string,class:class-string}
 */
function writeAuditStaleShard(int $format, array $entries): array
{
    $container = (new ContainerBuilder())->build();
    $attributes = $container->get(AttributeDefinitionRegistry::class);
    $parameters = $container->get(ParametersResolver::class);
    expect($attributes)->toBeInstanceOf(AttributeDefinitionRegistry::class)
        ->and($parameters)->toBeInstanceOf(ParametersResolver::class);

    /** @var AttributeDefinitionRegistry $attributes */
    /** @var ParametersResolver $parameters */
    $fingerprint = compiled_factory_pipeline_fingerprint($attributes, $parameters);
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
    public const string PIPELINE_FINGERPRINT = %s;

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
        var_export($fingerprint, true),
        ObjectPipeline::class,
        AuditStaleAotEntry::class,
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

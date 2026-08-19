<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\DI\Compile\Factory\CompiledFactoryDefinition;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\InvalidConfigurationException;

final class AuditObsoleteShardEntry {}

test('explicit old fast-path shards fail closed with a configuration error', function (): void {
    $suffix = bin2hex(random_bytes(5));
    $namespace = 'Componenta\\DI\\Tests\\Generated\\Obsolete' . $suffix;
    $class = $namespace . '\\Shard';
    $file = sys_get_temp_dir() . '/componenta-di-obsolete-shard-' . $suffix . '.php';

    $code = sprintf(
        <<<'PHP'
<?php

declare(strict_types=1);

namespace %s;

final class Shard
{
    public const string PIPELINE_FINGERPRINT = '%s';
    public const array FAST_PATHS = [];

    public function createEntry(array $params = []): object
    {
        return new \stdClass();
    }
}

return Shard::class;
PHP,
        $namespace,
        str_repeat('0', 64),
    );

    file_put_contents($file, $code);

    try {
        $container = (new ContainerBuilder())
            ->addDefinition(
                AuditObsoleteShardEntry::class,
                new CompiledFactoryDefinition($file, $class, 'createEntry'),
            )
            ->build();

        expect(fn() => $container->make(AuditObsoleteShardEntry::class))
            ->toThrow(InvalidConfigurationException::class, 'obsolete semantic fast-path format');
    } finally {
        @unlink($file);
    }
});

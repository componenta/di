<?php

declare(strict_types=1);

namespace Componenta\DI\Verification;

use Componenta\Config\Config;
use Componenta\DI\Compile\Factory\CompiledFactoryDefinition;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

final readonly class Dependency {}

final readonly class Entry
{
    public function __construct(
        public Dependency $dependency,
        public int $value = 1,
    ) {}
}

$directory = sys_get_temp_dir() . '/componenta-di-verification-' . bin2hex(random_bytes(5));

try {
    $factories = (new ContainerBuilder())->compileFactories([Entry::class], $directory);

    if (!isset($factories[Entry::class], $factories[Dependency::class])
        || !$factories[Entry::class] instanceof CompiledFactoryDefinition
    ) {
        throw new \RuntimeException('The autowiring graph was not compiled into factories.');
    }

    $container = ContainerBuilder::configureFromCache(
        new Config([]),
        [
            'version' => ContainerBuilder::CACHE_VERSION,
            ConfigKey::DEPENDENCIES => [ConfigKey::FACTORIES => $factories],
        ],
        $directory,
    )->build();
    $entry = $container->make(Entry::class, ['value' => 42]);

    if ($entry->value !== 42 || !$entry->dependency instanceof Dependency) {
        throw new \RuntimeException('Compiled factory behavior differs from runtime autowiring.');
    }

    echo "Componenta DI compiled-factory verification passed.\n";
} finally {
    foreach (glob($directory . '/container.factories.*.php') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($directory);
}

<?php

declare(strict_types=1);

namespace Componenta\DI\Benchmarks\AliasGraph;

use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerFactory;
use RuntimeException;
use stdClass;

final class BenchmarkInvokable {}

$autoload = getenv('COMPONENTA_DI_BENCH_AUTOLOAD');
$source = getenv('COMPONENTA_DI_BENCH_SOURCE');
if (!is_string($autoload) || !is_file($autoload) || !is_string($source) || !is_dir($source)) {
    throw new RuntimeException('Set COMPONENTA_DI_BENCH_AUTOLOAD and COMPONENTA_DI_BENCH_SOURCE.');
}
(require __DIR__ . '/autoload.php')($autoload, $source);

$config = new Config([], new Environment([]));
$factory = new ContainerFactory();
$factory->create($config, new DependencyDefinitions([]));
$value = new stdClass();
printf("PHP %s, source %s\n", PHP_VERSION, $source);

foreach ([300, 600, 1200, 2400] as $size) {
    $chain = $invokables = [];
    for ($index = 0; $index < $size; ++$index) {
        $id = 'alias.' . $index;
        $chain[$id] = $index === $size - 1 ? 'source' : 'alias.' . ($index + 1);
        $invokables[$id] = BenchmarkInvokable::class;
    }
    $cases = [
        'chain/head-first' => [ConfigKey::ALIASES => $chain, ConfigKey::SERVICES => ['source' => $value]],
        'chain/terminal-first' => [ConfigKey::ALIASES => array_reverse($chain, preserve_keys: true), ConfigKey::SERVICES => ['source' => $value]],
        'invokables/implicit' => [ConfigKey::INVOKABLES => $invokables],
        'invokables/matching' => [ConfigKey::INVOKABLES => $invokables, ConfigKey::ALIASES => $invokables],
    ];

    foreach ($cases as $case => $sections) {
        $definitions = new DependencyDefinitions($sections);
        $samples = [];
        for ($round = 0; $round < 7; ++$round) {
            gc_collect_cycles();
            $started = hrtime(true);
            $container = $factory->create($config, $definitions)->container;
            $samples[] = hrtime(true) - $started;
            if ($container->get('alias.0') !== $container->get('alias.' . ($size - 1))) {
                throw new RuntimeException('The aliases must resolve to the same shared entry.');
            }
            unset($container);
        }
        sort($samples, SORT_NUMERIC);
        printf("%-36s %14.1f ns\n", $case . '/' . $size, $samples[3]);
    }
}

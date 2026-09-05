<?php

declare(strict_types=1);

namespace {
    use Composer\Autoload\ClassLoader;

    $autoload = getenv('COMPONENTA_DI_BENCH_AUTOLOAD');
    $source = getenv('COMPONENTA_DI_BENCH_SOURCE');

    if (!is_string($autoload) || !is_file($autoload)) {
        throw new RuntimeException('COMPONENTA_DI_BENCH_AUTOLOAD must point to vendor/autoload.php.');
    }

    if (!is_string($source) || !is_dir($source)) {
        throw new RuntimeException('COMPONENTA_DI_BENCH_SOURCE must point to the DI src directory.');
    }

    $loader = require $autoload;
    if (!$loader instanceof ClassLoader) {
        throw new RuntimeException('Composer autoloader is unavailable.');
    }

    $loader->setPsr4('Componenta\\DI\\', rtrim($source, '/\\') . '/');
}

namespace Componenta\DI\Benchmarks\Build {
    use Componenta\Config\Config;
    use Componenta\Config\DependencyDefinitions;
    use Componenta\Config\Environment;
    use Componenta\DI\ConfigKey;
    use Componenta\DI\ContainerFactory;

    final readonly class BenchmarkInvokable {}

    /** @param array<string,mixed> $sections */
    function buildContainer(array $sections): object
    {
        return (new ContainerFactory())->create(
            new Config([], new Environment([])),
            new DependencyDefinitions($sections),
        )->container;
    }

    /** @return array{nanoseconds:float,operations:float} */
    function benchmark(callable $operation, int $iterations, int $rounds = 7): array
    {
        for ($index = 0; $index < min(500, $iterations); ++$index) {
            $operation();
        }

        $samples = [];
        for ($round = 0; $round < $rounds; ++$round) {
            gc_collect_cycles();
            $started = hrtime(true);
            for ($index = 0; $index < $iterations; ++$index) {
                $operation();
            }
            $samples[] = (hrtime(true) - $started) / $iterations;
        }

        sort($samples, SORT_NUMERIC);
        $nanoseconds = $samples[intdiv(count($samples), 2)];

        return [
            'nanoseconds' => $nanoseconds,
            'operations' => 1_000_000_000 / $nanoseconds,
        ];
    }

    $iterations = max(1_000, (int) ($_SERVER['DI_BUILD_ITERATIONS'] ?? 20_000));
    $representative = [
        ConfigKey::SERVICES => [
            'benchmark.base' => 'base',
        ],
        ConfigKey::FACTORIES => [
            'benchmark.factory' => static fn(): object => new \stdClass(),
        ],
        ConfigKey::INVOKABLES => [
            'benchmark.invokable' => BenchmarkInvokable::class,
        ],
        ConfigKey::ALIASES => [
            'benchmark.alias' => 'benchmark.base',
        ],
        ConfigKey::DELEGATORS => [
            'benchmark.base' => [
                static fn(string $entry): string => $entry . ':decorated',
            ],
        ],
    ];

    $cases = [
        'build/empty' => static fn(): object => buildContainer([]),
        'build/representative' => static fn(): object => buildContainer($representative),
    ];

    printf("PHP %s, builds %d\n", PHP_VERSION, $iterations);
    printf("%-24s %14s %14s\n", 'case', 'latency', 'operations/s');
    printf("%-24s %14s %14s\n", str_repeat('-', 24), str_repeat('-', 14), str_repeat('-', 14));

    foreach ($cases as $name => $operation) {
        $result = benchmark($operation, $iterations);
        printf(
            "%-24s %10.1f ns %14.0f\n",
            $name,
            $result['nanoseconds'],
            $result['operations'],
        );
    }
}

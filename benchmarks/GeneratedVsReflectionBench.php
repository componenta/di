<?php

declare(strict_types=1);

namespace {
    use Composer\Autoload\ClassLoader;

    $autoload = getenv('COMPONENTA_DI_BENCH_AUTOLOAD');
    $source = getenv('COMPONENTA_DI_BENCH_SOURCE');

    $autoload = is_string($autoload) && $autoload !== ''
        ? $autoload
        : dirname(__DIR__) . '/vendor/autoload.php';
    $source = is_string($source) && $source !== ''
        ? $source
        : dirname(__DIR__) . '/src';

    if (!is_file($autoload)) {
        throw new RuntimeException('Benchmark autoload file does not exist: ' . $autoload);
    }
    if (!is_dir($source)) {
        throw new RuntimeException('Benchmark DI source directory does not exist: ' . $source);
    }

    $loader = require $autoload;
    if (!$loader instanceof ClassLoader) {
        throw new RuntimeException('Composer autoloader is unavailable.');
    }

    $loader->setPsr4('Componenta\\DI\\', rtrim($source, '/\\') . '/');
}

namespace Componenta\DI\Benchmarks\Generated {
    use Componenta\Config\Config;
    use Componenta\DI\ConfigKey;
    use Componenta\DI\ContainerBuilder;

    final readonly class BenchmarkDependency {}

    final readonly class BenchmarkEntry
    {
        public function __construct(
            public BenchmarkDependency $dependency,
            public int $number = 1,
            public string $name = 'default',
        ) {}
    }

    /** @return array{nanoseconds: float, operations: float} */
    function benchmark(callable $operation, int $iterations, int $rounds = 7): array
    {
        for ($index = 0; $index < min(2_000, $iterations); ++$index) {
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

        return ['nanoseconds' => $nanoseconds, 'operations' => 1_000_000_000 / $nanoseconds];
    }

    $iterations = max(10_000, (int) ($_SERVER['DI_BENCH_ITERATIONS'] ?? 100_000));
    $directory = sys_get_temp_dir() . '/componenta-di-benchmark-' . bin2hex(random_bytes(5));
    $override = ['number' => 42];

    try {
        $compileStarted = hrtime(true);
        $compiler = new ContainerBuilder();
        $factories = $compiler->compileFactories([BenchmarkEntry::class], $directory);
        $compileMilliseconds = (hrtime(true) - $compileStarted) / 1_000_000;

        $reflectionBuildStarted = hrtime(true);
        $reflection = (new ContainerBuilder())->build();
        $reflectionBuildMilliseconds = (hrtime(true) - $reflectionBuildStarted) / 1_000_000;

        $compiledBuildStarted = hrtime(true);
        $compiled = ContainerBuilder::configureFromCache(
            new Config([]),
            [
                'version' => ContainerBuilder::CACHE_VERSION,
                ConfigKey::DEPENDENCIES => [ConfigKey::FACTORIES => $factories],
            ],
            $directory,
        )->build();
        $compiledBuildMilliseconds = (hrtime(true) - $compiledBuildStarted) / 1_000_000;

        $reflectionDefault = benchmark(
            static fn(): object => $reflection->make(BenchmarkEntry::class),
            $iterations,
        );
        $compiledDefault = benchmark(
            static fn(): object => $compiled->make(BenchmarkEntry::class),
            $iterations,
        );
        $reflectionOverride = benchmark(
            static fn(): object => $reflection->make(BenchmarkEntry::class, $override),
            $iterations,
        );
        $compiledOverride = benchmark(
            static fn(): object => $compiled->make(BenchmarkEntry::class, $override),
            $iterations,
        );

        printf("PHP %s, iterations %d\n", PHP_VERSION, $iterations);
        printf("factory compilation: %.3f ms, shards: %d\n", $compileMilliseconds, count(glob($directory . '/container.factories.*.php') ?: []));
        printf("container build reflection: %.3f ms\n", $reflectionBuildMilliseconds);
        printf("container build compiled:   %.3f ms\n", $compiledBuildMilliseconds);
        printf("%-22s %10.1f ns %12.0f ops/s\n", 'reflection/default', $reflectionDefault['nanoseconds'], $reflectionDefault['operations']);
        printf("%-22s %10.1f ns %12.0f ops/s\n", 'compiled/default', $compiledDefault['nanoseconds'], $compiledDefault['operations']);
        printf("%-22s %10.1f ns %12.0f ops/s\n", 'reflection/override', $reflectionOverride['nanoseconds'], $reflectionOverride['operations']);
        printf("%-22s %10.1f ns %12.0f ops/s\n", 'compiled/override', $compiledOverride['nanoseconds'], $compiledOverride['operations']);
        printf("speedup default: %.2fx\n", $reflectionDefault['nanoseconds'] / $compiledDefault['nanoseconds']);
        printf("speedup override: %.2fx\n", $reflectionOverride['nanoseconds'] / $compiledOverride['nanoseconds']);
    } finally {
        foreach (glob($directory . '/container.factories.*.php') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
}

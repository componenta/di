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

namespace Componenta\DI\Benchmarks\FactoryVsReflection {
    use Componenta\Config\Config;
    use Componenta\Config\ContainerValue;
    use Componenta\Config\DependencyDefinitions;
    use Componenta\Config\Environment;
    use Componenta\DI\ConfigKey;
    use Componenta\DI\Container;
    use Componenta\DI\ContainerFactory;

    final readonly class BenchmarkDependency {}

    final readonly class BenchmarkEntry
    {
        public function __construct(
            public BenchmarkDependency $dependency,
            public int $number = 1,
            public string $name = 'default',
        ) {}
    }

    /** @param array<string,mixed> $sections */
    function createContainer(array $sections = []): Container
    {
        $container = (new ContainerFactory())->create(
            new Config([], new Environment([])),
            new DependencyDefinitions($sections),
        )->container;

        if (!$container instanceof Container) {
            throw new \RuntimeException('ContainerFactory returned an unsupported container implementation.');
        }

        return $container;
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
    $override = ['number' => 42];

    $reflectionBuildStarted = hrtime(true);
    $reflection = createContainer();
    $reflectionBuildMilliseconds = (hrtime(true) - $reflectionBuildStarted) / 1_000_000;

    $factoryBuildStarted = hrtime(true);
    $factory = createContainer([
        ConfigKey::FACTORIES => [
            BenchmarkEntry::class => static function (
                ContainerValue $value,
                array $params,
            ): BenchmarkEntry {
                return new BenchmarkEntry(
                    $value->container->get(BenchmarkDependency::class),
                    $params['number'] ?? 1,
                    $params['name'] ?? 'default',
                );
            },
        ],
    ]);
    $factoryBuildMilliseconds = (hrtime(true) - $factoryBuildStarted) / 1_000_000;

    $reflectionDefault = benchmark(
        static fn(): object => $reflection->make(BenchmarkEntry::class),
        $iterations,
    );
    $factoryDefault = benchmark(
        static fn(): object => $factory->make(BenchmarkEntry::class),
        $iterations,
    );
    $reflectionOverride = benchmark(
        static fn(): object => $reflection->make(BenchmarkEntry::class, $override),
        $iterations,
    );
    $factoryOverride = benchmark(
        static fn(): object => $factory->make(BenchmarkEntry::class, $override),
        $iterations,
    );

    printf("PHP %s, iterations %d\n", PHP_VERSION, $iterations);
    printf("container build reflection: %.3f ms\n", $reflectionBuildMilliseconds);
    printf("container build factory:    %.3f ms\n", $factoryBuildMilliseconds);
    printf("%-22s %10.1f ns %12.0f ops/s\n", 'reflection/default', $reflectionDefault['nanoseconds'], $reflectionDefault['operations']);
    printf("%-22s %10.1f ns %12.0f ops/s\n", 'factory/default', $factoryDefault['nanoseconds'], $factoryDefault['operations']);
    printf("%-22s %10.1f ns %12.0f ops/s\n", 'reflection/override', $reflectionOverride['nanoseconds'], $reflectionOverride['operations']);
    printf("%-22s %10.1f ns %12.0f ops/s\n", 'factory/override', $factoryOverride['nanoseconds'], $factoryOverride['operations']);
    printf("factory/reflection default ratio: %.2fx\n", $factoryDefault['nanoseconds'] / $reflectionDefault['nanoseconds']);
    printf("factory/reflection override ratio: %.2fx\n", $factoryOverride['nanoseconds'] / $reflectionOverride['nanoseconds']);
}

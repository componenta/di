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

namespace Componenta\DI\Benchmarks\Runtime {
    use Componenta\DI\ContainerBuilder;

    final class Dependency {}

    final class NoArguments {}

    final readonly class ConstructorTarget
    {
        public function __construct(public Dependency $dependency) {}
    }

    final class MethodTarget
    {
        public function execute(Dependency $dependency): Dependency
        {
            return $dependency;
        }
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

        return [
            'nanoseconds' => $nanoseconds,
            'operations' => 1_000_000_000 / $nanoseconds,
        ];
    }

    $iterations = max(10_000, (int) ($_SERVER['DI_BENCH_ITERATIONS'] ?? 100_000));
    $buildIterations = max(100, (int) ($_SERVER['DI_BUILD_ITERATIONS'] ?? 2_000));
    $container = (new ContainerBuilder())->build();
    $closure = static fn(Dependency $dependency): Dependency => $dependency;
    $method = [new MethodTarget(), 'execute'];

    $cases = [
        'build/default' => [
            static fn(): object => (new ContainerBuilder())->build(),
            $buildIterations,
        ],
        'make/no-arguments' => [
            static fn(): object => $container->make(NoArguments::class),
            $iterations,
        ],
        'make/autowire' => [
            static fn(): object => $container->make(ConstructorTarget::class),
            $iterations,
        ],
        'call/reused-closure' => [
            static fn(): mixed => $container->call($closure),
            $iterations,
        ],
        'call/fresh-closure' => [
            static fn(): mixed => $container->call(
                static fn(Dependency $dependency): Dependency => $dependency,
            ),
            $iterations,
        ],
        'call/method-array' => [
            static fn(): mixed => $container->call($method),
            $iterations,
        ],
    ];

    printf("PHP %s, source %s\n", PHP_VERSION, $source);
    printf("%-22s %14s %14s\n", 'case', 'latency', 'operations/s');
    printf("%-22s %14s %14s\n", str_repeat('-', 22), str_repeat('-', 14), str_repeat('-', 14));

    foreach ($cases as $name => [$operation, $caseIterations]) {
        $result = benchmark($operation, $caseIterations);

        printf(
            "%-22s %10.1f ns %14.0f\n",
            $name,
            $result['nanoseconds'],
            $result['operations'],
        );
    }
}

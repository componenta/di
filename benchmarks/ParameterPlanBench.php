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

namespace Componenta\DI\Benchmarks\ParameterPlan {
    use Componenta\DI\ContainerBuilder;
    use Componenta\DI\Resolver\Parameter\ParametersResolver;
    use ReflectionMethod;

    final class ParameterShapes
    {
        public function one(int $a): void {}

        public function three(int $a, string $b, bool $c): void {}

        public function six(
            int $a,
            string $b,
            bool $c,
            float $d,
            array $e,
            mixed $f,
        ): void {}
    }

    /** @return array{nanoseconds:float,operations:float} */
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
    $container = (new ContainerBuilder())->build();
    $parameters = $container->get(ParametersResolver::class);
    if (!$parameters instanceof ParametersResolver) {
        throw new \RuntimeException('ParametersResolver bootstrap service is unavailable.');
    }

    $providedByMethod = [
        'one' => ['a' => 1],
        'three' => ['a' => 1, 'b' => 'value', 'c' => true],
        'six' => ['a' => 1, 'b' => 'value', 'c' => true, 'd' => 1.5, 'e' => [], 'f' => null],
    ];

    printf("PHP %s, iterations %d\n", PHP_VERSION, $iterations);
    printf("%-28s %14s %14s\n", 'case', 'latency', 'operations/s');
    printf("%-28s %14s %14s\n", str_repeat('-', 28), str_repeat('-', 14), str_repeat('-', 14));

    foreach ($providedByMethod as $method => $provided) {
        $reflection = new ReflectionMethod(ParameterShapes::class, $method);
        $targets = $parameters->targets(array_values($reflection->getParameters()));
        $plan = $parameters->prepareTargets($targets);

        $cases = [
            $method . '/prepare' => static fn() => $parameters->prepareTargets($targets),
            $method . '/resolve-targets' => static fn() => $parameters->resolveTargets($targets, $provided),
            $method . '/resolve-prepared' => static fn() => $parameters->resolvePrepared($plan, $provided),
        ];

        foreach ($cases as $name => $operation) {
            $result = benchmark($operation, $iterations);
            printf(
                "%-28s %10.1f ns %14.0f\n",
                $name,
                $result['nanoseconds'],
                $result['operations'],
            );
        }
    }
}

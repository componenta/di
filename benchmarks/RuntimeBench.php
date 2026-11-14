<?php

declare(strict_types=1);

namespace {
    $autoload = getenv('COMPONENTA_DI_BENCH_AUTOLOAD');
    $source = getenv('COMPONENTA_DI_BENCH_SOURCE');

    if (!is_string($autoload) || !is_file($autoload)) {
        throw new RuntimeException('COMPONENTA_DI_BENCH_AUTOLOAD must point to vendor/autoload.php.');
    }

    if (!is_string($source) || !is_dir($source)) {
        throw new RuntimeException('COMPONENTA_DI_BENCH_SOURCE must point to the DI src directory.');
    }

    $loader = (require __DIR__ . '/autoload.php')($autoload, $source);
}

namespace Componenta\DI\Benchmarks\Runtime {
    use Componenta\Config\Config;
    use Componenta\Config\DependencyDefinitions;
    use Componenta\Config\Environment;
    use Componenta\DI\Attribute\SetUp;
    use Componenta\DI\Attribute\Transform;
    use Componenta\DI\Container;
    use Componenta\DI\ContainerFactory;
    use Componenta\DI\Exception\ResolutionException;

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

    interface Left {}
    interface Right {}
    final class LeftValue implements Left {}
    final class RightValue implements Right {}

    #[SetUp('configure')]
    final class MethodSetupTarget
    {
        public function configure(Dependency $dependency): void {}
    }

    #[SetUp(SETUP_CALLBACK)]
    final class CallbackSetupTarget {}

    final class TransformTargets
    {
        public static function none(int $value): int
        {
            return $value;
        }

        public static function zeroArguments(#[Transform(ZERO_CALLBACK)] int $value): int
        {
            return $value;
        }

        public static function one(#[Transform(VALUE_CALLBACK)] int $value): int
        {
            return $value;
        }

        public static function three(#[Transform(VALUE_CALLBACK), Transform(VALUE_CALLBACK), Transform(VALUE_CALLBACK)] int $value): int
        {
            return $value;
        }

        public static function suffix(#[Transform(SUFFIX_CALLBACK)] int $value): int
        {
            return $value;
        }

        public static function union(Left|Right|false $value = false): Left|Right|false
        {
            return $value;
        }
    }

    function createContainer(): Container
    {
        // The pinned v4 baseline uses ContainerBuilder instead of ContainerFactory.
        $container = class_exists(ContainerFactory::class)
            ? (new ContainerFactory())->create(
                new Config([], new Environment([])),
                new DependencyDefinitions([]),
            )->container
            : (new \Componenta\DI\ContainerBuilder())->build();

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

        return [
            'nanoseconds' => $nanoseconds,
            'operations' => 1_000_000_000 / $nanoseconds,
        ];
    }

    /** @param array<string,array{callable,int}> $cases */
    function reportCases(array $cases): void
    {
        $selection = getenv('DI_BENCH_CASES');
        $selected = is_string($selection) && $selection !== '' ? explode(',', $selection) : [];
        foreach ($cases as $name => [$operation, $caseIterations]) {
            if ($selected !== [] && !in_array($name, $selected, true)) {
                continue;
            }
            $result = benchmark($operation, $caseIterations);
            printf(
                "%-22s %10.1f ns %14.0f\n",
                $name,
                $result['nanoseconds'],
                $result['operations'],
            );
        }
    }

    $iterations = max(10_000, (int) ($_SERVER['DI_BENCH_ITERATIONS'] ?? 100_000));
    $buildIterations = max(100, (int) ($_SERVER['DI_BUILD_ITERATIONS'] ?? 2_000));
    $container = createContainer();
    $closure = static fn(Dependency $dependency): Dependency => $dependency;
    $method = [new MethodTarget(), 'execute'];

    $cases = [
        'build/default' => [
            static fn(): object => createContainer(),
            $buildIterations,
        ],
        'get/shared' => [
            static fn(): mixed => $container->get(Dependency::class),
            $iterations,
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
    // Finish comparable cases before creating feature-only container graphs.
    reportCases($cases);

    // Runtime constants keep this harness parseable on PHP 8.4. Native PHP 8.5
    // expression identity and lifetime are established by separate contract tests.
    if (class_exists(Transform::class)) {
        $cases = [];
        define(__NAMESPACE__ . '\\ZERO_CALLBACK', static fn(): int => 1);
        define(__NAMESPACE__ . '\\VALUE_CALLBACK', static fn(int $value): int => $value + 1);
        define(__NAMESPACE__ . '\\SUFFIX_CALLBACK', static fn(int $value, Dependency $dependency): int => $value + 1);
        define(__NAMESPACE__ . '\\SETUP_CALLBACK', static function (CallbackSetupTarget $entry, Dependency $dependency): void {});

        foreach (['none', 'zeroArguments', 'one', 'three', 'suffix'] as $shape) {
            $cases['transform/' . $shape] = [
                static fn(): mixed => $container->call([TransformTargets::class, $shape], ['value' => 1]),
                $iterations,
            ];
        }
        $cases['setup/method'] = [static fn(): object => $container->make(MethodSetupTarget::class), $iterations];
        $cases['setup/callback'] = [static fn(): object => $container->make(CallbackSetupTarget::class), $iterations];

        $oneCandidate = createContainer();
        $oneCandidate->set(Left::class, new LeftValue());
        $twoCandidates = createContainer();
        $twoCandidates->set(Left::class, new LeftValue());
        $twoCandidates->set(Right::class, new RightValue());
        $cases['union/zero'] = [
            static fn(): mixed => $container->call([TransformTargets::class, 'union']),
            $iterations,
        ];
        $cases['union/one'] = [
            static fn(): mixed => $oneCandidate->call([TransformTargets::class, 'union']),
            $iterations,
        ];
        $cases['union/two-rejected'] = [
            static function () use ($twoCandidates): void {
                try {
                    $twoCandidates->call([TransformTargets::class, 'union']);
                } catch (ResolutionException) {
                    return;
                }
                throw new \RuntimeException('The ambiguous union must be rejected.');
            },
            $iterations,
        ];
        reportCases($cases);
    }
}

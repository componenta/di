<?php

declare(strict_types=1);

namespace {
    ob_start();
    require dirname(__DIR__) . '/verification/run.php';
    ob_end_clean();
}

namespace Componenta\DI\Benchmarks {
    use Componenta\DI\Compile\Attribute\AttributeCodeGenerator;
    use Componenta\DI\Compile\Entry\GeneratedEntryResolverGenerator;
    use Componenta\DI\Compile\Entry\GeneratedEntryResolverLoader;
    use Componenta\DI\Compile\Entry\GeneratedEntryResolverWriter;
    use Componenta\DI\Compile\Factory\FactoryCodeGenerator;
    use Componenta\DI\Compile\Parameter\DefaultParameterResolverCodeGenerators;
    use Componenta\DI\Compile\Parameter\ParameterCodeGenerator;
    use Componenta\DI\Resolver\Attribute\AttributeHandlerRegistry;
    use Componenta\DI\Resolver\Attribute\AttributeProcessor;
    use Componenta\DI\Resolver\Entry\InstanceCreator;
    use Componenta\DI\Resolver\Entry\ReflectionResolver;
    use Componenta\DI\Resolver\Parameter\ArrayResolver;
    use Componenta\DI\Resolver\Parameter\DefaultValueResolver;
    use Componenta\DI\Resolver\Parameter\NullableResolver;
    use Componenta\DI\Resolver\Parameter\ParametersResolver;
    use RuntimeException;
    use Verification\FakeProxyFactory;

    final class SimpleEntry
    {
        public function __construct(
            public int $number = 1,
            public string $name = 'default',
            public ?object $payload = null,
        ) {}
    }

    final class MediumEntry
    {
        public function __construct(
            public int $a = 1,
            public int $b = 2,
            public int $c = 3,
            public int $d = 4,
            public int $e = 5,
            public int $f = 6,
            public int $g = 7,
            public int $h = 8,
            public int $i = 9,
            public int $j = 10,
        ) {}
    }

    final class NoArgumentsEntry
    {
        public function __construct() {}
    }

    /** @return array{nanoseconds: float, operations: float} */
    function benchmark(callable $operation, int $iterations, int $rounds = 5): array
    {
        for ($index = 0; $index < 2_000; ++$index) {
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

    function milliseconds(callable $operation, int $iterations = 10): float
    {
        $samples = [];

        for ($round = 0; $round < 5; ++$round) {
            $started = hrtime(true);

            for ($index = 0; $index < $iterations; ++$index) {
                $operation();
            }

            $samples[] = (hrtime(true) - $started) / $iterations / 1_000_000;
        }

        sort($samples, SORT_NUMERIC);

        return $samples[intdiv(count($samples), 2)];
    }

    $iterations = max(10_000, (int) ($_SERVER['DI_BENCH_ITERATIONS'] ?? 200_000));
    $mediumIterations = max(5_000, intdiv($iterations, 4));

    $parameters = new ParametersResolver(
        new ArrayResolver(),
        new DefaultValueResolver(),
        new NullableResolver(),
    );
    $attributes = new AttributeProcessor(new AttributeHandlerRegistry());
    $proxyFactory = new FakeProxyFactory();
    $reflection = new ReflectionResolver(
        new InstanceCreator($parameters),
        $attributes,
        $proxyFactory,
    );
    $parameterGenerators = DefaultParameterResolverCodeGenerators::create();
    $factoryGenerator = new FactoryCodeGenerator(
        new ParameterCodeGenerator(
            $parameters,
            $parameterGenerators,
        ),
        $attributes,
        new AttributeCodeGenerator(),
    );
    $entryGenerator = new GeneratedEntryResolverGenerator(
        $factoryGenerator,
        $parameters,
        $attributes,
        $parameterGenerators,
    );
    $classes = [
        SimpleEntry::class,
        MediumEntry::class,
        NoArgumentsEntry::class,
    ];
    $generatedFile = sys_get_temp_dir()
        . '/componenta-di-benchmark-'
        . getmypid()
        . '.php';
    $writer = new GeneratedEntryResolverWriter();
    $loader = new GeneratedEntryResolverLoader();
    $releaseFingerprint = 'benchmark-release';

    $generationMilliseconds = milliseconds(
        static fn(): string => $entryGenerator->generate(
            $classes,
            'Componenta\\DI\\Benchmarks\\Generated',
            $releaseFingerprint,
        ),
    );

    $writer->write(
        $generatedFile,
        $entryGenerator->generate(
            $classes,
            'Componenta\\DI\\Benchmarks\\GeneratedRuntime',
            $releaseFingerprint,
        ),
    );

    $strictLoadMilliseconds = milliseconds(
        static fn() => $loader->load(
            $generatedFile,
            $parameters->resolverList,
            $attributes->registry->handlers,
            $proxyFactory,
        ),
    );
    $releaseLoadMilliseconds = milliseconds(
        static fn() => $loader->load(
            $generatedFile,
            $parameters->resolverList,
            $attributes->registry->handlers,
            $proxyFactory,
            $releaseFingerprint,
        ),
    );
    $generated = $loader->load(
        $generatedFile,
        $parameters->resolverList,
        $attributes->registry->handlers,
        $proxyFactory,
        $releaseFingerprint,
    );

    if ($generated === null) {
        throw new RuntimeException('Generated benchmark resolver did not load.');
    }

    $cases = [
        'simple/default' => [
            static fn(): object => $reflection->resolve(SimpleEntry::class),
            static fn(): object => $generated->resolve(SimpleEntry::class),
            $iterations,
        ],
        'simple/override' => [
            static fn(): object => $reflection->resolve(
                SimpleEntry::class,
                ['number' => 21],
            ),
            static fn(): object => $generated->resolve(
                SimpleEntry::class,
                ['number' => 21],
            ),
            $iterations,
        ],
        'medium/default' => [
            static fn(): object => $reflection->resolve(MediumEntry::class),
            static fn(): object => $generated->resolve(MediumEntry::class),
            $mediumIterations,
        ],
        'no-arguments' => [
            static fn(): object => $reflection->resolve(NoArgumentsEntry::class),
            static fn(): object => $generated->resolve(NoArgumentsEntry::class),
            $iterations,
        ],
    ];

    printf("PHP %s, iterations %d\n\n", PHP_VERSION, $iterations);
    printf("%-20s %14s %14s %10s\n", 'case', 'reflection', 'generated', 'speedup');
    printf("%-20s %14s %14s %10s\n", str_repeat('-', 20), str_repeat('-', 14), str_repeat('-', 14), str_repeat('-', 10));

    foreach ($cases as $name => [$reflectionCase, $generatedCase, $caseIterations]) {
        $reflectionResult = benchmark($reflectionCase, $caseIterations);
        $generatedResult = benchmark($generatedCase, $caseIterations);
        $speedup = $reflectionResult['nanoseconds'] / $generatedResult['nanoseconds'];

        printf(
            "%-20s %10.1f ns %10.1f ns %9.2fx\n",
            $name,
            $reflectionResult['nanoseconds'],
            $generatedResult['nanoseconds'],
            $speedup,
        );
    }

    printf("\ncode generation: %.3f ms\n", $generationMilliseconds);
    printf("strict cache validation: %.3f ms\n", $strictLoadMilliseconds);
    printf("release cache validation: %.3f ms\n", $releaseLoadMilliseconds);

    @unlink($generatedFile);
}

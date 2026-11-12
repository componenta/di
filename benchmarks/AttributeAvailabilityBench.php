<?php

declare(strict_types=1);

/**
 * Run from the repository root, using the PHP binary being compared:
 *
 * COMPONENTA_DI_BENCH_AUTOLOAD="$PWD/vendor/autoload.php" \
 * COMPONENTA_DI_BENCH_SOURCE="$PWD/src" \
 * DI_BENCH_ITERATIONS=10000 DI_BENCH_ROUNDS=7 \
 * php -n benchmarks/AttributeAvailabilityBench.php
 *
 * Both COMPONENTA_DI_BENCH_* paths are required, as in the other benchmarks.
 * DI_BENCH_ITERATIONS and DI_BENCH_ROUNDS are optional positive integers.
 * Each case/round gets an independent container. Bootstrap, contamination and
 * warmup are excluded from timing and autoload counts. The counting loader is
 * prepended and observes only the eight deliberately unavailable fixture names.
 */

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

namespace Componenta\DI\Benchmarks\AttributeAvailability {
    use Componenta\Config\Config;
    use Componenta\Config\DependencyDefinitions;
    use Componenta\Config\Environment;
    use Componenta\DI\Container;
    use Componenta\DI\ContainerFactory;
    use RuntimeException;

    final class NoArguments {}

    // These attribute classes must never be defined or loaded by this probe.
    #[MissingOptionalOne]
    final class OneUnavailableAttribute {}

    #[MissingOptionalOne]
    #[MissingOptionalTwo]
    #[MissingOptionalThree]
    #[MissingOptionalFour]
    #[MissingOptionalFive]
    #[MissingOptionalSix]
    #[MissingOptionalSeven]
    #[MissingOptionalEight]
    final class EightUnavailableAttributes {}

    function createContainer(): Container
    {
        $container = (new ContainerFactory())->create(
            new Config([], new Environment([])),
            new DependencyDefinitions([]),
        )->container;

        if (!$container instanceof Container) {
            throw new RuntimeException('ContainerFactory returned an unsupported container implementation.');
        }

        return $container;
    }

    function positiveEnvironmentInteger(string $name, int $default): int
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            return $default;
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($integer)) {
            throw new RuntimeException($name . ' must be a positive integer.');
        }

        return $integer;
    }

    /** @param non-empty-list<float|int> $samples */
    function median(array $samples): float
    {
        sort($samples, SORT_NUMERIC);
        $middle = intdiv(count($samples), 2);

        return count($samples) % 2 === 1
            ? (float) $samples[$middle]
            : ($samples[$middle - 1] + $samples[$middle]) / 2;
    }

    /** @param list<class-string> $attributes */
    function assertUnavailable(array $attributes): void
    {
        foreach ($attributes as $attribute) {
            if (class_exists($attribute, false)
                || interface_exists($attribute, false)
                || trait_exists($attribute, false)
            ) {
                throw new RuntimeException('An unavailable benchmark attribute was loaded: ' . $attribute);
            }
        }
    }

    $iterations = positiveEnvironmentInteger('DI_BENCH_ITERATIONS', 10_000);
    $rounds = positiveEnvironmentInteger('DI_BENCH_ROUNDS', 7);
    $warmup = min(2_000, $iterations);
    $attributes = [
        MissingOptionalOne::class,
        MissingOptionalTwo::class,
        MissingOptionalThree::class,
        MissingOptionalFour::class,
        MissingOptionalFive::class,
        MissingOptionalSix::class,
        MissingOptionalSeven::class,
        MissingOptionalEight::class,
    ];
    $countedNames = array_fill_keys(array_map('strtolower', $attributes), true);
    $autoloadCalls = 0;
    $counter = static function (string $class) use ($countedNames, &$autoloadCalls): void {
        if (isset($countedNames[strtolower(ltrim($class, '\\'))])) {
            ++$autoloadCalls;
        }
    };
    $cases = [
        'make/clean' => ['mode' => 'make', 'fixture' => null],
        'make/1-unavailable' => ['mode' => 'make', 'fixture' => OneUnavailableAttribute::class],
        'make/8-unavailable' => ['mode' => 'make', 'fixture' => EightUnavailableAttributes::class],
        'get-shared/clean' => ['mode' => 'get', 'fixture' => null],
        'get-shared/1-unavailable' => ['mode' => 'get', 'fixture' => OneUnavailableAttribute::class],
        'get-shared/8-unavailable' => ['mode' => 'get', 'fixture' => EightUnavailableAttributes::class],
    ];
    $samples = [];

    printf("PHP %s (%s, %s), binary %s\n", PHP_VERSION, PHP_OS_FAMILY, php_uname('m'), PHP_BINARY);
    printf("source %s\n", $source);
    printf(
        "iterations %d, rounds %d, warmup %d per case/round\n",
        $iterations,
        $rounds,
        $warmup,
    );
    printf(
        "OPcache CLI %s, JIT %s, JIT buffer %s\n",
        ini_get('opcache.enable_cli') ?: 'off/unavailable',
        ini_get('opcache.jit') ?: 'off/unavailable',
        ini_get('opcache.jit_buffer_size') ?: '0/unavailable',
    );

    spl_autoload_register($counter, true, true);
    try {
        assertUnavailable($attributes);
        for ($round = 0; $round < $rounds; ++$round) {
            // Alternate ordering to reduce a fixed clean-versus-contaminated bias.
            $orderedCases = $round % 2 === 0 ? $cases : array_reverse($cases, true);
            foreach ($orderedCases as $name => $case) {
                $container = createContainer();
                if ($case['fixture'] !== null) {
                    $container->make($case['fixture']);
                }
                $operation = $case['mode'] === 'make'
                    ? static fn(): object => $container->make(NoArguments::class)
                    : static fn(): mixed => $container->get(NoArguments::class);

                for ($index = 0; $index < $warmup; ++$index) {
                    $operation();
                }
                assertUnavailable($attributes);
                gc_collect_cycles();
                $autoloadCalls = 0;
                $started = hrtime(true);
                for ($index = 0; $index < $iterations; ++$index) {
                    $operation();
                }
                $elapsed = hrtime(true) - $started;
                $samples[$name][] = [
                    'nanoseconds' => $elapsed / $iterations,
                    'autoloadCalls' => $autoloadCalls,
                ];
                assertUnavailable($attributes);
                unset($operation, $container);
            }
        }
    } finally {
        spl_autoload_unregister($counter);
    }

    printf("%-27s %14s %14s %18s %20s\n", 'case', 'median latency', 'operations/s', 'autoload/op median', 'autoload/round range');
    printf("%-27s %14s %14s %18s %20s\n", str_repeat('-', 27), str_repeat('-', 14), str_repeat('-', 14), str_repeat('-', 18), str_repeat('-', 20));
    foreach ($cases as $name => $_) {
        $caseSamples = $samples[$name];
        $nanoseconds = median(array_column($caseSamples, 'nanoseconds'));
        $counts = array_column($caseSamples, 'autoloadCalls');
        printf(
            "%-27s %10.1f ns %14.0f %18.2f %9d..%-9d\n",
            $name,
            $nanoseconds,
            1_000_000_000 / $nanoseconds,
            median($counts) / $iterations,
            min($counts),
            max($counts),
        );
    }

    printf("Counts cover only unavailable fixture names; timings include the counting loader.\n");
}

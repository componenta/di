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
    use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
    use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
    use Componenta\DI\CallableExecutorInterface;
    use Componenta\DI\ContainerBuilder;
    use Componenta\DI\Object\ObjectPipeline;
    use Componenta\DI\ProxyFactoryInterface;
    use Componenta\DI\Resolver\Entry\EntryResolverInterface;
    use Componenta\DI\Resolver\Parameter\ParametersResolver;
    use Psr\Container\ContainerInterface;

    final class ProfilingBuilder extends ContainerBuilder
    {
        /** @var array<string, int> */
        public static array $nanoseconds = [];

        /** @var array<string, int> */
        public static array $calls = [];

        public static function reset(): void
        {
            self::$nanoseconds = [];
            self::$calls = [];
        }

        protected function createProxyFactory(): ProxyFactoryInterface
        {
            $started = hrtime(true);

            try {
                return parent::createProxyFactory();
            } finally {
                self::record('proxy factory', $started);
            }
        }

        protected function createEntryResolver(
            ContainerInterface $container,
            ProxyFactoryInterface $proxyFactory,
            ObjectPipeline $objects,
            CallableExecutorInterface $executor,
            AttributeDefinitionRegistry $attributes,
            ParametersResolver $parameters,
        ): EntryResolverInterface {
            $started = hrtime(true);

            try {
                return parent::createEntryResolver(
                    $container,
                    $proxyFactory,
                    $objects,
                    $executor,
                    $attributes,
                    $parameters,
                );
            } finally {
                self::record('entry resolver graph', $started);
            }
        }

        protected function defaultParameterResolvers(
            ContainerInterface $container,
            AttributePlanBuilder $plans,
        ): array {
            $started = hrtime(true);

            try {
                return parent::defaultParameterResolvers($container, $plans);
            } finally {
                self::record('default parameter set', $started);
            }
        }

        private static function record(string $phase, int $started): void
        {
            self::$nanoseconds[$phase] = (self::$nanoseconds[$phase] ?? 0)
                + hrtime(true) - $started;
            self::$calls[$phase] = (self::$calls[$phase] ?? 0) + 1;
        }
    }

    $iterations = max(1_000, (int) ($_SERVER['DI_BUILD_ITERATIONS'] ?? 20_000));

    for ($index = 0; $index < min(1_000, $iterations); ++$index) {
        (new ProfilingBuilder())->build();
    }

    ProfilingBuilder::reset();
    gc_collect_cycles();
    $started = hrtime(true);

    for ($index = 0; $index < $iterations; ++$index) {
        (new ProfilingBuilder())->build();
    }

    $total = (hrtime(true) - $started) / $iterations;
    $accounted = 0.0;

    printf("PHP %s, builds %d\n", PHP_VERSION, $iterations);

    foreach (ProfilingBuilder::$nanoseconds as $phase => $nanoseconds) {
        $average = $nanoseconds / ProfilingBuilder::$calls[$phase];
        $accounted += $average;
        printf("%-24s %10.1f ns %6.1f%%\n", $phase, $average, $average / $total * 100);
    }

    $other = $total - $accounted;

    printf("%-24s %10.1f ns %6.1f%%\n", 'other bootstrap/seal', $other, $other / $total * 100);
    printf("%-24s %10.1f ns %6.1f%%\n", 'total', $total, 100.0);
}

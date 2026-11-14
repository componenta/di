<?php

declare(strict_types=1);

/**
 * Targeted boundary cost probe. Run baseline/current in separate processes,
 * serially, with the same PHP binary, vendor and source-selection loader.
 *
 * Required environment:
 *   COMPONENTA_DI_BENCH_AUTOLOAD=/absolute/vendor/autoload.php
 *   COMPONENTA_DI_BENCH_SOURCE=/absolute/selected-di/src
 *   COMPONENTA_DI_BENCH_LOADER=/absolute/di/benchmarks/autoload.php
 * Optional positive integers:
 *   DI_BENCH_ITERATIONS=100000 DI_BUILD_ITERATIONS=2000 DI_BENCH_ROUNDS=7
 *
 * Outputs JSON to stdout; the shared loader also reports its full declaration
 * and function origin check to stderr on shutdown. Inputs are prepared outside
 * timing. Every build creates a fresh container; PHP classes stay loaded.
 * Factory routes contain benign reference slots, never rebound by the fixtures.
 * This measures the complete operation, without subtracting unrelated timings.
 */

namespace {
    $autoload = getenv('COMPONENTA_DI_BENCH_AUTOLOAD');
    $source = getenv('COMPONENTA_DI_BENCH_SOURCE');
    $bootstrap = getenv('COMPONENTA_DI_BENCH_LOADER');
    if (!is_string($autoload) || !is_file($autoload)) {
        throw new RuntimeException('COMPONENTA_DI_BENCH_AUTOLOAD must point to vendor/autoload.php.');
    }
    if (!is_string($source) || !is_dir($source)) {
        throw new RuntimeException('COMPONENTA_DI_BENCH_SOURCE must point to the selected DI src directory.');
    }
    if (!is_string($bootstrap) || !is_file($bootstrap)) {
        throw new RuntimeException('COMPONENTA_DI_BENCH_LOADER must point to the shared benchmarks/autoload.php.');
    }

    $loader = (require $bootstrap)($autoload, $source);
    $source = str_replace('\\', '/', realpath($source));
}

namespace Componenta\DI\Benchmarks\Boundary {
    use Attribute;
    use Componenta\Config\Config;
    use Componenta\Config\DependencyDefinitions;
    use Componenta\Config\Environment;
    use Componenta\DI\Attribute\Composition\AttributeDefinition;
    use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
    use Componenta\DI\Attribute\Composition\AttributePlan;
    use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
    use Componenta\DI\ConfigKey;
    use Componenta\DI\Container;
    use Componenta\DI\ContainerFactory;
    use Componenta\DI\Internal\BootstrapResolutionGuard;
    use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
    use Componenta\DI\Resolver\Parameter\ParameterAttributeValue;
    use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
    use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
    use Componenta\DI\Resolver\Target\ParameterTarget;
    use Psr\Container\ContainerInterface;
    use ReflectionClass;
    use RuntimeException;

    #[Attribute(Attribute::TARGET_CLASS)]
    readonly class RegistryParent {}

    #[Attribute(Attribute::TARGET_CLASS)]
    final readonly class RegistryChild extends RegistryParent {}

    #[Attribute(Attribute::TARGET_PARAMETER)]
    final readonly class ExtensionValue {}

    final readonly class BenchmarkResolver implements ParameterResolverInterface
    {
        public function supports(ParameterTarget $target): bool
        {
            return $target->name === 'boundaryValue';
        }

        public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): ?array
        {
            return [$target->position, 17];
        }
    }

    final readonly class BenchmarkHandler implements ParameterAttributeHandlerInterface
    {
        public function resolveParameter(
            object $attribute,
            ParameterTarget $target,
            ParameterResolutionContext $context,
            AttributePlan $plan,
            ParameterAttributeValue $value,
        ): ParameterAttributeValue {
            return ParameterAttributeValue::resolved(23);
        }
    }

    final readonly class StaticExtensions
    {
        public static function resolver(ContainerInterface $container): ParameterResolverInterface
        {
            return new BenchmarkResolver();
        }

        public static function attribute(ContainerInterface $container): AttributeDefinition
        {
            return new AttributeDefinition(ExtensionValue::class, new BenchmarkHandler(), [ValueProvider::class]);
        }
    }

    final readonly class ServiceExtensions
    {
        public function resolver(ContainerInterface $container): ParameterResolverInterface
        {
            return new BenchmarkResolver();
        }

        public function attribute(ContainerInterface $container): AttributeDefinition
        {
            return new AttributeDefinition(ExtensionValue::class, new BenchmarkHandler(), [ValueProvider::class]);
        }
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

    function buildContainer(Config $config, DependencyDefinitions $definitions): object
    {
        return (new ContainerFactory())->create($config, $definitions)->container;
    }

    function checkExtensions(object $container): void
    {
        if (!$container instanceof Container
            || $container->call(static fn(int $boundaryValue): int => $boundaryValue) !== 17
            || $container->call(static fn(#[ExtensionValue] int $fromAttribute): int => $fromAttribute) !== 23
        ) {
            throw new RuntimeException('The extension factories must produce working resolver and attribute definitions.');
        }
    }

    $lookupIterations = positiveEnvironmentInteger('DI_BENCH_ITERATIONS', 100_000);
    $buildIterations = positiveEnvironmentInteger('DI_BUILD_ITERATIONS', 2_000);
    $rounds = positiveEnvironmentInteger('DI_BENCH_ROUNDS', 7);

    // The shared loader verifies every production DI declaration/function at
    // shutdown. Fail early if any directly measured owner has another origin.
    $origins = [];
    foreach ([
        AttributeDefinitionRegistry::class => 'Attribute/Composition/AttributeDefinitionRegistry.php',
        AttributeDefinition::class => 'Attribute/Composition/AttributeDefinition.php',
        ContainerFactory::class => 'ContainerFactory.php',
        BootstrapResolutionGuard::class => 'Internal/BootstrapResolutionGuard.php',
    ] as $class => $relativeFile) {
        $file = (new ReflectionClass($class))->getFileName();
        $actual = is_string($file) ? str_replace('\\', '/', realpath($file)) : '';
        if ($actual !== $source . '/' . $relativeFile) {
            throw new RuntimeException('Wrong benchmark source for ' . $class . ': ' . $actual);
        }
        $origins[$class] = ['file' => $actual, 'sha256' => hash_file('sha256', $actual)];
    }

    $definition = new AttributeDefinition(RegistryParent::class);
    $registry = new AttributeDefinitionRegistry();
    $registry->register($definition);
    $registry->seal();
    if (!class_exists(RegistryChild::class, false) || $registry->definition(RegistryChild::class) !== $definition) {
        throw new RuntimeException('The loaded descendant must select its registered parent definition.');
    }

    $config = new Config([], new Environment([]));
    $staticOwner = StaticExtensions::class;
    $staticResolverMethod = 'resolver';
    $staticAttributeMethod = 'attribute';
    $staticDefinitions = new DependencyDefinitions([
        ConfigKey::PARAMETER_RESOLVERS => [500 => [&$staticOwner, &$staticResolverMethod]],
        ConfigKey::ATTRIBUTE_DEFINITIONS => [[&$staticOwner, &$staticAttributeMethod]],
    ]);

    $serviceOwner = 'boundary.extension.owner';
    $serviceResolverMethod = 'resolver';
    $serviceAttributeMethod = 'attribute';
    $serviceDefinitions = new DependencyDefinitions([
        ConfigKey::SERVICES => [$serviceOwner => new ServiceExtensions()],
        ConfigKey::PARAMETER_RESOLVERS => [500 => [&$serviceOwner, &$serviceResolverMethod]],
        ConfigKey::ATTRIBUTE_DEFINITIONS => [[&$serviceOwner, &$serviceAttributeMethod]],
    ]);

    // Correctness checks and all input construction stay outside timed loops.
    checkExtensions(buildContainer($config, $staticDefinitions));
    checkExtensions(buildContainer($config, $serviceDefinitions));

    $cases = [
        'registry/loaded-derived' => [
            'operation' => static fn(): ?AttributeDefinition => $registry->definition(RegistryChild::class),
            'iterations' => $lookupIterations,
            'warmup' => min(2_000, $lookupIterations),
            'description' => 'One registered parent definition; loaded descendant; no class loading or plan cache.',
        ],
        'build/static-extension-tuple' => [
            'operation' => static fn(): object => buildContainer($config, $staticDefinitions),
            'iterations' => $buildIterations,
            'warmup' => min(100, $buildIterations),
            'description' => 'Fresh container; one static resolver tuple and one static attribute-definition tuple.',
        ],
        'build/service-extension-tuple' => [
            'operation' => static fn(): object => buildContainer($config, $serviceDefinitions),
            'iterations' => $buildIterations,
            'warmup' => min(100, $buildIterations),
            'description' => 'Fresh container; two instance-method tuples resolved through one registered factory-owner service.',
        ],
    ];

    $samples = [];
    for ($round = 0; $round < $rounds; ++$round) {
        $orderedCases = $round % 2 === 0 ? $cases : array_reverse($cases, true);
        foreach ($orderedCases as $name => $case) {
            $operation = $case['operation'];
            $caseIterations = $case['iterations'];
            $caseWarmup = $case['warmup'];
            for ($index = 0; $index < $caseWarmup; ++$index) {
                $operation();
            }
            gc_collect_cycles();
            $last = null;
            $started = hrtime(true);
            for ($index = 0; $index < $caseIterations; ++$index) {
                $last = $operation();
            }
            $samples[$name][] = (hrtime(true) - $started) / $caseIterations;
            if ($name === 'registry/loaded-derived') {
                if ($last !== $definition) {
                    throw new RuntimeException('Registry selection changed during measurement.');
                }
            } elseif (!$last instanceof Container) {
                throw new RuntimeException('The build operation did not return a container.');
            }
            unset($last);
        }
    }

    if ([$staticOwner, $staticResolverMethod, $staticAttributeMethod] !== [StaticExtensions::class, 'resolver', 'attribute']
        || [$serviceOwner, $serviceResolverMethod, $serviceAttributeMethod] !== ['boundary.extension.owner', 'resolver', 'attribute']
    ) {
        throw new RuntimeException('A benign factory route was unexpectedly rebound.');
    }

    $results = [];
    foreach ($cases as $name => $case) {
        $results[$name] = [
            'description' => $case['description'],
            'iterations_per_round' => $case['iterations'],
            'warmup_per_round' => $case['warmup'],
            'median_ns_per_operation' => median($samples[$name]),
            'min_ns_per_operation' => min($samples[$name]),
            'max_ns_per_operation' => max($samples[$name]),
            'samples_ns_per_operation' => $samples[$name],
        ];
    }

    echo json_encode([
        'benchmark' => 'attribute-and-extension-boundaries',
        'script_sha256' => hash_file('sha256', __FILE__),
        'runtime' => [
            'version' => PHP_VERSION,
            'binary' => PHP_BINARY,
            'architecture' => php_uname('m'),
            'opcache_enable_cli' => ini_get('opcache.enable_cli'),
            'opcache_jit' => ini_get('opcache.jit'),
            'opcache_jit_buffer_size' => ini_get('opcache.jit_buffer_size'),
        ],
        'source' => $source,
        'vendor_autoload' => realpath($autoload),
        'source_loader' => realpath($bootstrap),
        'source_loader_sha256' => hash_file('sha256', $bootstrap),
        'direct_owner_origin_checks' => $origins,
        'rounds' => $rounds,
        'case_order' => 'alternates forward/reverse per round',
        'build_inputs' => 'Config and DependencyDefinitions reused; fresh container and extension values per operation; two benign reference tuples per build.',
        'gc' => [
            'automatic_enabled' => gc_enabled(),
            'manual_collection' => 'Immediately before each timed case/round; no changes to automatic GC.',
        ],
        'results' => $results,
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

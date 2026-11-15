<?php

declare(strict_types=1);

namespace Componenta\DI\Benchmarks\OptimizationWorkloads;

use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\Attribute\Transform;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;
use Componenta\DI\Definition\Definition;
use Psr\Container\ContainerInterface;
use RuntimeException;

// Usage: php -n TargetedOptimizationWorkloads.php <source-tree> <vendor/autoload.php> [iterations] [builds]
// Defaults: 50,000 normal calls and 20 batches of 100 container builds.
// Build count must be a positive multiple of 100; batches preserve the original GC boundary.
// Compare immutable sources in serial ABBA rounds under the same binary/vendor.
if ($argc < 3 || $argc > 5) {
    throw new RuntimeException('Supply source tree, vendor autoloader, optional iterations and builds.');
}
$source = realpath($argv[1]);
$autoload = realpath($argv[2]);
$iterations = filter_var($argv[3] ?? 50000, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$builds = filter_var($argv[4] ?? 2000, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($source === false || !is_file($source . '/benchmarks/autoload.php')
    || $autoload === false || !is_file($autoload) || $iterations === false || $builds === false
    || $builds % 100 !== 0
) {
    throw new RuntimeException('Invalid source, dependency autoloader or iteration count.');
}
(require $source . '/benchmarks/autoload.php')($autoload, $source . '/src');

final class Dependency {}
final class PlainTarget {}
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
final class TransformTargets
{
    public static function one(#[Transform(INCREMENT)] int $value): int
    {
        return $value;
    }

    public static function three(#[Transform(INCREMENT), Transform(INCREMENT), Transform(INCREMENT)] int $value): int
    {
        return $value;
    }
}
#[SetUp('initialize')]
final class SetupTarget
{
    public ?Dependency $dependency = null;

    public function initialize(Dependency $dependency): void
    {
        $this->dependency = $dependency;
    }
}
final readonly class ExternalOwner implements ContainerInterface
{
    public function __construct(private Dependency $dependency) {}

    public function has(string $id): bool
    {
        return $id === 'external';
    }

    public function get(string $id): Dependency
    {
        if ($id !== 'external') {
            throw new RuntimeException('Unexpected external lookup.');
        }
        return $this->dependency;
    }
}

function container(): Container
{
    return (new ContainerFactory())->create(new Config([], new Environment([])), new DependencyDefinitions([]))->container;
}

/** @return array{iterations:int, elapsed_ns:int, ns_per_operation:float} */
function measure(callable $operation, callable $verify, int $iterations, int $warmup): array
{
    $verify($operation());
    for ($index = 0; $index < $warmup; ++$index) {
        $operation();
    }
    gc_collect_cycles();
    gc_disable();
    try {
        $last = null;
        $started = hrtime(true);
        for ($index = 0; $index < $iterations; ++$index) {
            $last = $operation();
        }
        $elapsed = hrtime(true) - $started;
    } finally {
        gc_enable();
    }
    $verify($last);
    return ['iterations' => $iterations, 'elapsed_ns' => $elapsed, 'ns_per_operation' => $elapsed / $iterations];
}

function requireValue(bool $condition): void
{
    if (!$condition) {
        throw new RuntimeException('A workload violated its expected result.');
    }
}

define(__NAMESPACE__ . '\\INCREMENT', static fn(int $value): int => $value + 1);
$container = container();
$dependency = $container->get(Dependency::class);
$reused = static fn(Dependency $value): Dependency => $value;
$method = [new MethodTarget(), 'execute'];
$external = container();
$external->addContainer(new ExternalOwner($dependency));
$fresh = container();
$fresh->set('chain.0', Definition::factory(static fn(): PlainTarget => new PlainTarget()));
for ($depth = 1; $depth <= 8; ++$depth) {
    $child = 'chain.' . ($depth - 1);
    $fresh->set('chain.' . $depth, Definition::factory(static fn(): object => $fresh->make($child)));
}

$cases = [
    'get/shared' => [static fn(): mixed => $container->get(Dependency::class), static fn(mixed $value) => requireValue($value === $dependency)],
    'get/external' => [static fn(): mixed => $external->get('external'), static fn(mixed $value) => requireValue($value === $dependency)],
    'make/plain' => [static fn(): object => $container->make(PlainTarget::class), static fn(mixed $value) => requireValue($value instanceof PlainTarget)],
    'make/autowire' => [static fn(): object => $container->make(ConstructorTarget::class), static fn(mixed $value) => requireValue($value instanceof ConstructorTarget && $value->dependency === $dependency)],
    'make/fresh-factory-chain-9' => [static fn(): object => $fresh->make('chain.8'), static fn(mixed $value) => requireValue($value instanceof PlainTarget)],
    'call/reused-closure' => [static fn(): mixed => $container->call($reused), static fn(mixed $value) => requireValue($value === $dependency)],
    'call/method-array' => [static fn(): mixed => $container->call($method), static fn(mixed $value) => requireValue($value === $dependency)],
    'transform/one' => [static fn(): mixed => $container->call([TransformTargets::class, 'one'], ['value' => 1]), static fn(mixed $value) => requireValue($value === 2)],
    'transform/three' => [static fn(): mixed => $container->call([TransformTargets::class, 'three'], ['value' => 1]), static fn(mixed $value) => requireValue($value === 4)],
    'setup/method' => [static fn(): object => $container->make(SetupTarget::class), static fn(mixed $value) => requireValue($value instanceof SetupTarget && $value->dependency === $dependency)],
];

// Filtering is outside timing; all original fixture construction above is retained.
$cases = array_intersect_key($cases, array_fill_keys(['get/shared', 'transform/one'], true));

// Verify fresh/shared identity outside the timed loops.
requireValue($container->make(PlainTarget::class) !== $container->make(PlainTarget::class));
requireValue($fresh->make('chain.8') !== $fresh->make('chain.8'));
$samples = [];
foreach ($cases as $name => [$operation, $verify]) {
    $samples[$name] = measure($operation, $verify, $iterations, min(1000, $iterations));
}
// The original measure() remains unchanged. Each batch retains at most the
// original 100-build accumulation while GC is disabled; its warmup, collection
// and verification are outside that batch's elapsed time.
$buildBatches = [];
for ($batch = 0; $batch < intdiv($builds, 100); ++$batch) {
    $buildBatches[] = measure(container(...), static fn(mixed $value) => requireValue($value instanceof Container), 100, 10);
}
$buildElapsed = array_sum(array_column($buildBatches, 'elapsed_ns'));
$samples['build/default'] = [
    'iterations' => $builds,
    'elapsed_ns' => $buildElapsed,
    'ns_per_operation' => $buildElapsed / $builds,
    'batch_size' => 100,
    'batches' => $buildBatches,
];

echo json_encode([
    'php' => PHP_VERSION,
    'binary' => PHP_BINARY,
    'source' => $source,
    'vendor_autoload' => $autoload,
    'ini' => php_ini_loaded_file(),
    'opcache_enable_cli' => ini_get('opcache.enable_cli'),
    'jit' => ini_get('opcache.jit'),
    'samples' => $samples,
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

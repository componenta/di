<?php

declare(strict_types=1);

namespace ComponentaAudit\CycleGuard;

use Closure;
use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;
use Componenta\DI\Definition\Definition;
use Fiber;
use RuntimeException;
use stdClass;

// Usage: php -n cycle-guard-benchmark.php SOURCE_ROOT VENDOR_DIR [SCALE=32768]
// Run workers serially. This measures complete named public operations. Input
// construction, metadata warmup, source checks and explicit GC are not timed.
require __DIR__ . '/source-loader.php';
$sourceRoot = realpath($argv[1] ?? '') ?: throw new RuntimeException('Provide source root.');
$vendorDirectory = realpath($argv[2] ?? '') ?: throw new RuntimeException('Provide vendor directory.');
$scale = isset($argv[3]) ? (int) $argv[3] : 32768;
if ($scale < 1024 || $scale % 1024 !== 0) {
    throw new RuntimeException('Scale must be a positive multiple of 1024.');
}
loadSource($sourceRoot, $vendorDirectory);

final class ReflectionLeaf {}

/** @return array<string, int|float> */
function repeatedOperation(Closure $operation, int $iterations, string $expectedClass = ''): array
{
    for ($index = 0; $index < 256; ++$index) {
        $operation();
    }
    gc_collect_cycles();
    $gcWasEnabled = gc_enabled();
    gc_disable();
    // BEGIN TIMED repeated_operation
    $start = hrtime(true);
    for ($index = 0; $index < $iterations; ++$index) {
        $last = $operation();
    }
    $elapsed = hrtime(true) - $start;
    // END TIMED repeated_operation
    if ($gcWasEnabled) {
        gc_enable();
    }
    if ($expectedClass !== '' && !$last instanceof $expectedClass) {
        throw new RuntimeException('Fresh creation returned the wrong type.');
    }
    return [
        'iterations' => $iterations,
        'elapsed_nanoseconds' => $elapsed,
        'nanoseconds_per_operation' => $elapsed / $iterations,
    ];
}

$factory = new ContainerFactory();
$config = new Config([], new Environment([]));
$ready = new stdClass();
$warm = $factory->create($config, new DependencyDefinitions(['services' => ['ready' => $ready]]))->container;
if (!$warm instanceof Container || $warm->get('ready') !== $ready || !$warm->has('ready')) {
    throw new RuntimeException('Warm service control failed.');
}
$reflectionFirst = $warm->make(ReflectionLeaf::class);
if (!$reflectionFirst instanceof ReflectionLeaf || $warm->make(ReflectionLeaf::class) === $reflectionFirst) {
    throw new RuntimeException('Fresh reflection identity control failed.');
}

$nested = $factory->create($config, new DependencyDefinitions([]))->container;
if (!$nested instanceof Container) {
    throw new RuntimeException('Expected native container.');
}
for ($level = 7; $level >= 0; --$level) {
    $next = 'nested.' . ($level + 1);
    $callback = $level === 7
        ? static fn(): object => new stdClass()
        : static fn(): object => $nested->make($next);
    $nested->set('nested.' . $level, Definition::factory($callback));
}
$nestedFirst = $nested->make('nested.0');
if (!$nestedFirst instanceof stdClass || $nested->make('nested.0') === $nestedFirst) {
    throw new RuntimeException('Nested factory freshness control failed.');
}

$measurements = [];
$measurements['has_resolved_value_main'] = repeatedOperation(static fn(): bool => $warm->has('ready'), $scale * 8);
$measurements['get_resolved_value_main_control'] = repeatedOperation(static fn(): mixed => $warm->get('ready'), $scale * 8, stdClass::class);
$measurements['make_reflection_leaf_main'] = repeatedOperation(static fn(): object => $warm->make(ReflectionLeaf::class), $scale, ReflectionLeaf::class);
$measurements['make_factory_depth_8_main'] = repeatedOperation(static fn(): object => $nested->make('nested.0'), intdiv($scale, 4), stdClass::class);
$fiber = new Fiber(static fn(): array => repeatedOperation(static fn(): object => $nested->make('nested.0'), intdiv($scale, 4), stdClass::class));
$fiber->start();
$measurements['make_factory_depth_8_fiber'] = $fiber->getReturn();

$coldDefinitions = new DependencyDefinitions([
    'factories' => ['cold' => static fn(): object => new stdClass()],
]);
$coldIterations = intdiv($scale, 8);
$coldSamples = [];
$gcWasEnabled = gc_enabled();
gc_disable();
for ($batch = -1; $batch < intdiv($coldIterations, 128); ++$batch) {
    $prepared = [];
    for ($index = 0; $index < 128; ++$index) {
        $prepared[] = $factory->create($config, $coldDefinitions)->container;
    }
    gc_collect_cycles();
    // BEGIN TIMED cold_shared_get
    $start = hrtime(true);
    foreach ($prepared as $container) {
        $last = $container->get('cold');
    }
    $elapsed = hrtime(true) - $start;
    // END TIMED cold_shared_get
    if (!$last instanceof stdClass || $container->get('cold') !== $last) {
        throw new RuntimeException('Cold shared factory identity control failed.');
    }
    if ($batch >= 0) {
        $coldSamples[] = $elapsed;
    }
    unset($container, $prepared, $last);
    gc_collect_cycles();
}
if ($gcWasEnabled) {
    gc_enable();
}
$measurements['get_cold_factory_main'] = [
    'iterations' => $coldIterations,
    'batch_size' => 128,
    'elapsed_nanoseconds' => array_sum($coldSamples),
    'nanoseconds_per_operation' => array_sum($coldSamples) / $coldIterations,
    'batch_nanoseconds' => $coldSamples,
];

echo json_encode([
    'php' => PHP_VERSION,
    'binary' => PHP_BINARY,
    'source_root' => $sourceRoot,
    'worker_sha256' => hash_file('sha256', __FILE__),
    'loader_sha256' => hash_file('sha256', __DIR__ . '/source-loader.php'),
    'opcache_enable_cli' => ini_get('opcache.enable_cli'),
    'measurement' => 'Named complete public operations including loop/closure dispatch; cold shared get uses one fresh prepared container per call. Setup, metadata warming, checks, Fiber creation and explicit GC are excluded.',
    'checks' => ['warm_shared_identity' => true, 'fresh_reflection_identity' => true, 'fresh_nested_identity' => true, 'cold_shared_identity' => true],
    'provenance' => sourceProvenance($sourceRoot),
    'measurements' => $measurements,
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), "\n";

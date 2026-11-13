<?php

declare(strict_types=1);

namespace DiPerformanceReassessment;

use Attribute;
use Closure;
use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\Inject;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\ConfigKey;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use RuntimeException;

/**
 * Baseline-only workload investigation; not an optimization comparison.
 *
 * Required environment:
 *   COMPONENTA_DI_BENCH_AUTOLOAD=/absolute/dependencies/vendor/autoload.php
 *   COMPONENTA_DI_BENCH_SOURCE=/absolute/immutable-source/src
 * Optional: DI_REASSESS_ITERATIONS=500, DI_REASSESS_COLD_ITERATIONS=40,
 *   DI_REASSESS_ROUNDS=5, DI_REASSESS_COUNT_ITERATIONS=20,
 *   DI_REASSESS_SOURCE_COMMIT=<full SHA>, DI_REASSESS_FILTER=<substring>.
 *
 * Latency uses only the selected Composer loader. Missing-name counters run in
 * a separate untimed phase with an added observing autoloader. Each measured
 * round owns its own container. All fixture classes are process-loaded, so
 * "cold" means fresh container/metadata/service state, not a new PHP process.
 */

$selection = require __DIR__ . '/autoload-selected-source.php';

#[Attribute(Attribute::TARGET_ALL)]
final class ForeignAttribute {}

#[Attribute(Attribute::TARGET_ALL)]
final class RuleMarker {}

final class NoOpRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void {}
}

final class Plain {}
final class Leaf {}

final readonly class Left
{
    public function __construct(public Leaf $leaf) {}
}

final readonly class Right
{
    public function __construct(public Leaf $leaf) {}
}

final readonly class Root
{
    public function __construct(public Left $left, public Right $right) {}
}

#[SetUp('initialize')]
final class CoreAttributes
{
    #[Inject]
    public Leaf $leaf;
    public bool $initialized = false;

    public function initialize(): void
    {
        $this->initialized = true;
    }
}

#[ForeignAttribute]
final class LoadedForeignContaminant {}

#[MissingOptional0]
final class OneMissingContaminant {}

#[MissingOptional0, MissingOptional1, MissingOptional2, MissingOptional3]
#[MissingOptional4, MissingOptional5, MissingOptional6, MissingOptional7]
final class EightMissingContaminant {}

/** @return array<string, string> */
function sourceHashes(string $source): array
{
    $hashes = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source)) as $file) {
        if ($file->isFile()) {
            $hashes[substr($file->getPathname(), strlen($source) + 1)] = hash_file('sha256', $file->getPathname());
        }
    }
    ksort($hashes);
    return $hashes;
}

/** @return array{checked_declarations:int,checked_functions:int,violations:array<string,mixed>} */
function assertProductionSources(string $source): array
{
    $declarations = 0;
    $functions = 0;
    $violations = [];
    foreach ([...get_declared_classes(), ...get_declared_interfaces(), ...get_declared_traits()] as $name) {
        if (!str_starts_with($name, 'Componenta\\DI\\')) {
            continue;
        }
        ++$declarations;
        $file = (new ReflectionClass($name))->getFileName();
        if (!is_string($file) || !str_starts_with(realpath($file), $source . '/')) {
            $violations[$name] = $file;
        }
    }
    foreach (get_defined_functions()['user'] as $name) {
        if (!str_starts_with(strtolower($name), 'componenta\\di\\')) {
            continue;
        }
        ++$functions;
        $file = (new \ReflectionFunction($name))->getFileName();
        if (!is_string($file) || !str_starts_with(realpath($file), $source . '/')) {
            $violations[$name] = $file;
        }
    }
    if ($violations !== []) {
        throw new RuntimeException('Production source mismatch: ' . json_encode($violations, JSON_THROW_ON_ERROR));
    }
    return ['checked_declarations' => $declarations, 'checked_functions' => $functions, 'violations' => $violations];
}

function newContainer(bool $customRule): Container
{
    $definitions = $customRule
        ? [ConfigKey::ATTRIBUTE_DEFINITIONS => [new AttributeDefinition(RuleMarker::class, rules: [new NoOpRule()])]]
        : [];
    $container = (new ContainerFactory())->create(
        new Config([], new Environment([])),
        new DependencyDefinitions($definitions),
    )->container;
    if (!$container instanceof Container) {
        throw new RuntimeException('Expected a Componenta container.');
    }
    return $container;
}

function observeContaminant(Container $container, string $state): void
{
    $class = match ($state) {
        'clean' => null,
        'loaded-foreign' => LoadedForeignContaminant::class,
        'missing-1' => OneMissingContaminant::class,
        'missing-8' => EightMissingContaminant::class,
        default => throw new RuntimeException('Unknown availability state.'),
    };
    if ($class !== null && !$container->has($class)) {
        throw new RuntimeException('The inert contaminant must remain constructible.');
    }
}

function freshGraph(Container $container): Root
{
    $left = $container->make(Left::class, ['leaf' => $container->make(Leaf::class)]);
    $right = $container->make(Right::class, ['leaf' => $container->make(Leaf::class)]);
    return $container->make(Root::class, ['left' => $left, 'right' => $right]);
}

/** @return Closure():mixed */
function operation(string $path, string $state, bool $customRule): Closure
{
    if (str_starts_with($path, 'container-cold/')) {
        return static function () use ($path, $state, $customRule): mixed {
            $container = newContainer($customRule);
            if ($path === 'container-cold/build-only') {
                return $container;
            }
            observeContaminant($container, $state);
            return $path === 'container-cold/build-and-observe'
                ? $container
                : $container->make(Root::class);
        };
    }

    $container = newContainer($customRule);
    observeContaminant($container, $state);
    $closure = static fn(Leaf $leaf): Leaf => $leaf;
    // Explicit setup: dependencies and each measured path are warmed before
    // timing. A later root make reuses Left/Right/Leaf services via get().
    $container->get(Leaf::class);
    $sharedRoot = $container->get(Root::class);
    $otherRoot = $container->make(Root::class);
    if ($sharedRoot === $otherRoot || $sharedRoot->left !== $otherRoot->left
        || $sharedRoot->right !== $otherRoot->right
        || $sharedRoot->left->leaf !== $sharedRoot->right->leaf
    ) {
        throw new RuntimeException('Shared graph fixture does not have the stated identity semantics.');
    }
    $freshA = freshGraph($container);
    $freshB = freshGraph($container);
    if ($freshA === $freshB || $freshA->left === $freshB->left
        || $freshA->right === $freshB->right
        || $freshA->left->leaf === $freshB->left->leaf
        || $freshA->right->leaf === $freshB->right->leaf
        || $freshA->left->leaf === $freshA->right->leaf
    ) {
        throw new RuntimeException('Explicit fresh graph fixture reused an instance.');
    }
    $core = $container->make(CoreAttributes::class);
    if (!$core->initialized || $core->leaf !== $container->get(Leaf::class)) {
        throw new RuntimeException('Core attributes did not execute.');
    }

    return match ($path) {
        'warm/make-plain' => static fn(): Plain => $container->make(Plain::class),
        'warm/make-core-attributes' => static fn(): CoreAttributes => $container->make(CoreAttributes::class),
        'warm/call-reused-closure' => static fn(): Leaf => $container->call($closure),
        'warm/make-root-shared-children' => static fn(): Root => $container->make(Root::class),
        'warm/make-explicit-fresh-five-node-graph' => static fn(): Root => freshGraph($container),
        'warm/get-shared-root' => static fn(): Root => $container->get(Root::class),
        default => throw new RuntimeException('Unknown workload path.'),
    };
}

function median(array $values): int|float
{
    sort($values, SORT_NUMERIC);
    return $values[intdiv(count($values), 2)];
}

$iterations = max(1, (int) (getenv('DI_REASSESS_ITERATIONS') ?: 500));
$coldIterations = max(1, (int) (getenv('DI_REASSESS_COLD_ITERATIONS') ?: 40));
$rounds = max(1, (int) (getenv('DI_REASSESS_ROUNDS') ?: 5));
$countIterations = max(1, (int) (getenv('DI_REASSESS_COUNT_ITERATIONS') ?: 20));
$filter = getenv('DI_REASSESS_FILTER') ?: '';
$sourceBefore = sourceHashes($selection['source']);
$paths = [
    'warm/make-plain',
    'warm/make-core-attributes',
    'warm/call-reused-closure',
    'warm/make-root-shared-children',
    'warm/make-explicit-fresh-five-node-graph',
    'warm/get-shared-root',
    'container-cold/build-only',
    'container-cold/build-and-observe',
    'container-cold/build-observe-first-root',
];
$cases = [];
foreach ([false, true] as $customRule) {
    foreach (['clean', 'loaded-foreign', 'missing-1', 'missing-8'] as $state) {
        foreach ($paths as $path) {
            // Build-only is independent of the availability state: do not
            // repeat identical controls under misleading availability names.
            if ($path === 'container-cold/build-only' && $state !== 'clean') {
                continue;
            }
            $key = ($customRule ? 'custom-rule-cache-disabled' : 'default-cache') . '/' . $state . '/' . $path;
            if ($filter !== '' && !str_contains($key, $filter)) {
                continue;
            }
            $cases[$key] = [
                'availability' => $state,
                'custom_rule_cache_disabled' => $customRule,
                'path' => $path,
                'iterations' => str_starts_with($path, 'container-cold/') ? $coldIterations : $iterations,
                'latency_rounds_ns_per_operation' => [],
            ];
        }
    }
}
if ($cases === []) {
    throw new RuntimeException('No cases selected.');
}

// Alternate forward/reverse traversal so every state is not always measured
// in the same relative position. This is a baseline workload characterization,
// not a paired candidate significance test.
for ($round = 0; $round < $rounds; ++$round) {
    $keys = array_keys($cases);
    if ($round % 2 === 1) {
        $keys = array_reverse($keys);
    }
    foreach ($keys as $key) {
        $case = $cases[$key];
        $run = operation($case['path'], $case['availability'], $case['custom_rule_cache_disabled']);
        $warmup = str_starts_with($case['path'], 'warm/') ? min(50, $case['iterations']) : 1;
        for ($index = 0; $index < $warmup; ++$index) {
            $run();
        }
        gc_collect_cycles();
        $start = hrtime(true);
        for ($index = 0; $index < $case['iterations']; ++$index) {
            $run();
        }
        $cases[$key]['latency_rounds_ns_per_operation'][] = (hrtime(true) - $start) / $case['iterations'];
        unset($run);
    }
}

$missing = [];
for ($index = 0; $index < 8; ++$index) {
    $name = __NAMESPACE__ . '\\MissingOptional' . $index;
    if (class_exists($name, false) || interface_exists($name, false) || trait_exists($name, false)) {
        throw new RuntimeException('A missing attribute fixture unexpectedly became available.');
    }
    $missing[strtolower($name)] = true;
}
$calls = 0;
$counter = static function (string $name) use (&$calls, $missing): void {
    if (isset($missing[strtolower($name)])) {
        ++$calls;
    }
};
spl_autoload_register($counter, prepend: true);
try {
    foreach ($cases as $key => $case) {
        $run = operation($case['path'], $case['availability'], $case['custom_rule_cache_disabled']);
        $run();
        $calls = 0;
        for ($index = 0; $index < $countIterations; ++$index) {
            $run();
        }
        $cases[$key]['missing_autoload_count_control'] = [
            'iterations' => $countIterations,
            'total' => $calls,
            'per_operation' => $calls / $countIterations,
        ];
        $cases[$key]['median_ns_per_operation'] = median($case['latency_rounds_ns_per_operation']);
        $cases[$key]['min_ns_per_operation'] = min($case['latency_rounds_ns_per_operation']);
        $cases[$key]['max_ns_per_operation'] = max($case['latency_rounds_ns_per_operation']);
        unset($run);
    }
} finally {
    spl_autoload_unregister($counter);
}

$sourceAfter = sourceHashes($selection['source']);
if ($sourceBefore !== $sourceAfter) {
    throw new RuntimeException('Selected source files changed while measuring.');
}
$packages = [];
$installedFile = $selection['vendor'] . '/composer/installed.json';
$installed = json_decode(file_get_contents($installedFile), true, flags: JSON_THROW_ON_ERROR);
foreach ($installed['packages'] ?? $installed as $package) {
    $packages[$package['name']] = ['version' => $package['version'], 'reference' => $package['source']['reference'] ?? $package['dist']['reference'] ?? null];
}
ksort($packages);
$lock = dirname($selection['vendor']) . '/composer.lock';
$result = [
    'kind' => 'baseline-workload-necessity',
    'generated_at_utc' => gmdate('c'),
    'php' => ['version' => PHP_VERSION, 'binary' => PHP_BINARY, 'os_family' => PHP_OS_FAMILY, 'architecture' => php_uname('m')],
    'runtime_settings' => [
        'opcache.enable_cli' => ini_get('opcache.enable_cli'),
        'opcache.jit' => ini_get('opcache.jit'),
        'opcache.jit_buffer_size' => ini_get('opcache.jit_buffer_size'),
        'memory_limit' => ini_get('memory_limit'),
        'gc_enabled' => gc_enabled(),
    ],
    'source' => [
        'directory' => $selection['source'],
        'claimed_commit' => getenv('DI_REASSESS_SOURCE_COMMIT') ?: null,
        'file_count' => count($sourceBefore),
        'manifest_sha256' => hash('sha256', json_encode($sourceBefore, JSON_THROW_ON_ERROR)),
        'files_sha256' => $sourceBefore,
        'unchanged_after_measurement' => true,
        'source_map_assertion' => assertProductionSources($selection['source']),
    ],
    'dependencies' => [
        'autoload' => $selection['autoload'],
        'composer_lock_sha256' => is_file($lock) ? hash_file('sha256', $lock) : null,
        'installed_json_sha256' => hash_file('sha256', $installedFile),
        'packages' => $packages,
    ],
    'harness' => [
        'file_sha256' => hash_file('sha256', __FILE__),
        'adapter_sha256' => hash_file('sha256', __DIR__ . '/autoload-selected-source.php'),
        'rounds' => $rounds,
        'warm_iterations' => $iterations,
        'cold_iterations' => $coldIterations,
        'count_iterations' => $countIterations,
        'filter' => $filter,
        'latency_has_observing_loader' => false,
        'counts_measured_separately' => true,
        'case_order' => 'alternating forward/reverse by round',
        'cold_scope' => 'new container and new metadata/service state; process and fixture classes already loaded',
        'custom_rule_control' => 'One unused registered marker with a no-op custom composition rule disables semantic plan caches globally; no expensive rule body is included.',
        'graph_identity' => 'Root make shares Left/Right/Leaf children; explicit fresh graph makes five objects and supplies child arguments explicitly; warmed shared get returns the same Root.',
        'setup_scope' => 'Warm setup is outside timing. Cold build-only and build-and-observe controls expose setup cost separately; do not subtract medians as a paired causal estimate.',
    ],
    'cases' => $cases,
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;

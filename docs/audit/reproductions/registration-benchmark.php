<?php

declare(strict_types=1);

namespace ComponentaAudit\ExternalRegistration;

use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;
use Composer\Autoload\ClassLoader;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionFunction;
use RuntimeException;

// Usage: php -n registration-benchmark.php SOURCE_ROOT VENDOR_DIR [ITERATIONS=4096]
// SOURCE_ROOT contains the archived src directory. Construction, setup, warming,
// correctness checks, source verification and cycle collection are not timed.
$sourceRoot = realpath($argv[1] ?? '') ?: throw new RuntimeException('Provide an archived source root.');
$vendorDirectory = realpath($argv[2] ?? '') ?: throw new RuntimeException('Provide the dependency vendor directory.');
$iterations = isset($argv[3]) ? (int) $argv[3] : 4096;
if ($iterations < 128 || $iterations % 128 !== 0 || !is_file($sourceRoot . '/src/Container.php')) {
    throw new RuntimeException('Iterations must be a positive multiple of 128, and source root must contain src.');
}

// Build the Composer loader explicitly: requiring the workspace autoload first
// would eagerly include its DI functions and retain stale optimized class paths.
require_once $vendorDirectory . '/composer/ClassLoader.php';
$loader = new ClassLoader($vendorDirectory);
$classMap = require $vendorDirectory . '/composer/autoload_classmap.php';
$mappedClasses = [];
foreach ($classMap as $class => $path) {
    if (!str_starts_with($class, 'Componenta\\DI\\')) {
        continue;
    }
    $marker = strrpos($path, '/src/');
    if (str_starts_with($class, 'Componenta\\DI\\Tests\\') || $marker === false) {
        unset($classMap[$class]);
        continue;
    }
    $selected = $sourceRoot . substr($path, $marker);
    if (!is_file($selected)) {
        // A reused vendor tree can retain entries for removed DI classes. They
        // must disappear from this loader instead of falling back to old source.
        unset($classMap[$class]);
        continue;
    }
    $classMap[$class] = $selected;
    $mappedClasses[$class] = $selected;
}
$loader->addClassMap($classMap);
foreach (require $vendorDirectory . '/composer/autoload_psr4.php' as $prefix => $directories) {
    if (!str_starts_with($prefix, 'Componenta\\DI\\')) {
        $loader->setPsr4($prefix, $directories);
    }
}
$loader->setPsr4('Componenta\\DI\\', $sourceRoot . '/src');
foreach (require $vendorDirectory . '/composer/autoload_namespaces.php' as $prefix => $directories) {
    $loader->set($prefix, $directories);
}
$loader->register(true);
foreach (require $vendorDirectory . '/composer/autoload_files.php' as $identifier => $path) {
    if (str_ends_with($path, '/src/Internal/functions.php')) {
        $path = $sourceRoot . '/src/Internal/functions.php';
    }
    if (!isset($GLOBALS['__composer_autoload_files'][$identifier])) {
        $GLOBALS['__composer_autoload_files'][$identifier] = true;
        require $path;
    }
}

final readonly class NativeEntries implements ContainerInterface
{
    /** @param array<string, mixed> $entries */
    public function __construct(private array $entries) {}

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->entries);
    }

    public function get(string $id): mixed
    {
        return $this->entries[$id] ?? throw new RuntimeException('Unexpected external entry: ' . $id);
    }
}

/** Used only for correctness probes outside the measured region. */
final class ObservedEntries implements ContainerInterface
{
    /** @var array<string, int> */
    public array $queries = [];

    /** @param array<string, mixed> $entries */
    public function __construct(private readonly array $entries) {}

    public function has(string $id): bool
    {
        $this->queries[$id] = ($this->queries[$id] ?? 0) + 1;
        return array_key_exists($id, $this->entries);
    }

    public function get(string $id): mixed
    {
        return $this->entries[$id] ?? throw new RuntimeException('Unexpected external entry: ' . $id);
    }
}

$local = static fn(object $base): object => (object) ['base' => $base, 'source' => 'local'];
$external = static fn(object $base): object => (object) ['base' => $base, 'source' => 'external'];
$services = [];
$delegators = [];
$remoteEntries = [];
$entryIds = [];
for ($index = 0; $index < 8; ++$index) {
    $id = 'entry.' . $index;
    $owner = 'owner.' . $index;
    $entryIds[] = $id;
    $services[$id] = (object) ['index' => $index];
    $services[$owner] = $local;
    $delegators[$id] = [$owner];
    $remoteEntries[$owner] = $external;
}
$config = new Config([], new Environment([]));
$empty = new DependencyDefinitions([]);
$defined = new DependencyDefinitions(['services' => $services, 'delegators' => $delegators]);
$factory = new ContainerFactory();
$scenarios = [
    'no_deferred_ids' => [$empty, null, [], []],
    'takeover_8' => [$defined, [], $remoteEntries, $entryIds],
    'candidate_misses_8' => [$defined, [], [], $entryIds],
    'existing_owner_8' => [$defined, $remoteEntries, $remoteEntries, $entryIds],
];

/** @param list<string> $ids */
function prepare(ContainerFactory $factory, Config $config, DependencyDefinitions $definitions, ?ContainerInterface $prior, array $ids): Container
{
    $container = $factory->create($config, $definitions)->container;
    if ($prior !== null) {
        $container->addContainer($prior);
    }
    foreach ($ids as $id) {
        $container->get($id);
    }
    return $container;
}

$checks = [];
foreach ($scenarios as $name => [$definitions, $priorEntries, $candidateEntries, $ids]) {
    $prior = $priorEntries === null ? null : new ObservedEntries($priorEntries);
    $candidate = new ObservedEntries($candidateEntries);
    $container = prepare($factory, $config, $definitions, $prior, $ids);
    $before = [];
    foreach ($ids as $id) {
        $before[$id] = $container->get($id);
    }
    if ($prior !== null) {
        $prior->queries = [];
    }
    $container->addContainer($candidate);
    $candidateQueries = $candidate->queries;
    $priorQueries = $prior?->queries ?? [];
    $expectedQueries = in_array($name, ['takeover_8', 'candidate_misses_8'], true)
        ? array_fill_keys(array_keys($remoteEntries), 1)
        : [];
    if ($candidateQueries !== $expectedQueries) {
        throw new RuntimeException('Candidate-once contract failed for ' . $name);
    }
    foreach ($ids as $id) {
        $after = $container->get($id);
        $expectedSource = $name === 'candidate_misses_8' ? 'local' : 'external';
        $shouldPreserve = $name !== 'takeover_8';
        if (!is_object($after) || $after->source !== $expectedSource || (($after === $before[$id]) !== $shouldPreserve)) {
            throw new RuntimeException('Registration outcome failed for ' . $name . ':' . $id);
        }
    }
    $checks[$name] = ['candidate_queries' => $candidateQueries, 'existing_owner_queries' => $priorQueries, 'passed' => true];
    unset($container, $before, $after, $candidate, $prior);
}

$measurements = [];
$gcWasEnabled = gc_enabled();
gc_disable();
foreach ($scenarios as $name => [$definitions, $priorEntries, $candidateEntries, $ids]) {
    $prior = $priorEntries === null ? null : new NativeEntries($priorEntries);
    $candidate = new NativeEntries($candidateEntries);
    $samples = [];
    // One untimed batch warms code paths and allocation behavior for this case.
    for ($batch = -1; $batch < intdiv($iterations, 128); ++$batch) {
        $prepared = [];
        for ($index = 0; $index < 128; ++$index) {
            $prepared[] = prepare($factory, $config, $definitions, $prior, $ids);
        }
        $start = hrtime(true);
        foreach ($prepared as $container) {
            $container->addContainer($candidate);
        }
        $duration = hrtime(true) - $start;
        if ($batch >= 0) {
            $samples[] = $duration;
        }
        unset($container, $prepared);
        gc_collect_cycles();
    }
    $measurements[$name] = [
        'iterations' => $iterations,
        'batch_size' => 128,
        'elapsed_nanoseconds' => array_sum($samples),
        'nanoseconds_per_registration' => array_sum($samples) / $iterations,
        'batch_nanoseconds' => $samples,
    ];
}
if ($gcWasEnabled) {
    gc_enable();
}

$loadedClasses = [];
foreach ([...get_declared_classes(), ...get_declared_interfaces(), ...get_declared_traits()] as $class) {
    if (str_starts_with($class, 'Componenta\\DI\\')) {
        $path = (new ReflectionClass($class))->getFileName();
        if (!is_string($path) || !str_starts_with($path, $sourceRoot . '/src/')) {
            throw new RuntimeException('Stale DI class source: ' . $class);
        }
        $loadedClasses[$class] = $path;
    }
}
$loadedFunctions = [];
foreach (get_defined_functions()['user'] as $function) {
    if (str_starts_with(strtolower($function), 'componenta\\di\\')) {
        $path = (new ReflectionFunction($function))->getFileName();
        if ($path !== $sourceRoot . '/src/Internal/functions.php') {
            throw new RuntimeException('Stale DI function source: ' . $function);
        }
        $loadedFunctions[$function] = $path;
    }
}
if ($loadedFunctions === [] || $mappedClasses === []) {
    throw new RuntimeException('Expected source provenance was not observed.');
}

echo json_encode([
    'php' => PHP_VERSION,
    'binary' => PHP_BINARY,
    'source_root' => $sourceRoot,
    'fixture_sha256' => hash_file('sha256', __FILE__),
    'opcache_enable_cli' => ini_get('opcache.enable_cli'),
    'measurement' => 'Only foreach dispatch and addContainer; fresh warmed container per call; setup and explicit cycle collection excluded.',
    'provenance' => ['mapped_di_classes' => $mappedClasses, 'loaded_di_classes' => $loadedClasses, 'loaded_di_functions' => $loadedFunctions],
    'checks' => $checks,
    'measurements' => $measurements,
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), "\n";

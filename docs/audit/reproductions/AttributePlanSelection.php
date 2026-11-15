<?php

declare(strict_types=1);

namespace Optimization\AttributeSelection;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeCapabilityInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Exception\AttributeCompositionException;
use ReflectionClass;
use RuntimeException;
use Throwable;

/**
 * Measure public AttributePlan selection helpers with one chosen source tree.
 *
 * Example from a checkout:
 * php -n docs/audit/reproductions/AttributePlanSelection.php --source=/path/to/di/src --autoload=/path/to/vendor/autoload.php
 *
 * The optional --loader path allows running this file outside a checkout.
 * Defaults retain the measured 100000 normal calls and 500 exception calls.
 * This is a helper microbenchmark, not an application throughput measurement.
 */
$options = getopt('', ['source:', 'autoload:', 'loader:', 'iterations:', 'exception-iterations:']);
$repository = dirname(__DIR__, 3);
$selectedSource = realpath($options['source'] ?? $repository . '/src');
if ($selectedSource === false) {
    throw new RuntimeException('The selected source directory does not exist.');
}
$selectedFile = $selectedSource . '/Attribute/Composition/AttributePlan.php';
$autoloadFile = $options['autoload'] ?? $repository . '/vendor/autoload.php';
$loaderFile = $options['loader'] ?? $repository . '/benchmarks/autoload.php';
$paths = [];
foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($selectedSource, \FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $paths[] = substr($file->getPathname(), strlen($selectedSource) + 1);
    }
}
sort($paths);
$sourceHashes = [];
$fingerprint = hash_init('sha256');
foreach ($paths as $path) {
    $bytes = file_get_contents($selectedSource . '/' . $path);
    if ($bytes === false) {
        throw new RuntimeException('Cannot read source file: ' . $path);
    }
    $relative = str_replace('\\', '/', $path);
    $sourceHashes[$relative] = hash('sha256', $bytes);
    hash_update($fingerprint, 'src/' . $relative . "\0" . $bytes . "\0");
}
$sourceFingerprint = hash_final($fingerprint);
$autoload = require $loaderFile;
$autoload($autoloadFile, $selectedSource);

interface FamilyCapability extends AttributeCapabilityInterface {}
interface FirstCapability extends FamilyCapability {}
interface LastCapability extends FamilyCapability {}
interface OtherCapability extends AttributeCapabilityInterface {}
interface MissingCapability extends AttributeCapabilityInterface {}

final class NativeArgument
{
    public static int $constructions = 0;

    public function __construct()
    {
        ++self::$constructions;
    }
}

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class FirstTag
{
    public function __construct(public NativeArgument $argument) {}
}

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class MiddleTag
{
    public function __construct(public NativeArgument $argument) {}
}

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class LastTag
{
    public function __construct(public NativeArgument $argument) {}
}

final class EmptyTarget {}

#[FirstTag(new NativeArgument())]
final class SingleTarget {}

#[FirstTag(new NativeArgument()), MiddleTag(new NativeArgument()), LastTag(new NativeArgument())]
final class InterleavedTarget {}

$registry = new AttributeDefinitionRegistry();
$registry->register(new AttributeDefinition(FirstTag::class, capabilities: [FirstCapability::class]));
$registry->register(new AttributeDefinition(MiddleTag::class, capabilities: [OtherCapability::class]));
$registry->register(new AttributeDefinition(LastTag::class, capabilities: [LastCapability::class]));
$builder = new AttributePlanBuilder($registry);
$empty = $builder->build(new ReflectionClass(EmptyTarget::class));
$single = $builder->build(new ReflectionClass(SingleTarget::class));
$interleaved = $builder->build(new ReflectionClass(InterleavedTarget::class));
$first = $interleaved->usages[0];
$last = $interleaved->usages[2];
class_exists(AttributeCompositionException::class);

function hasLoop(AttributePlan $plan, string $capability, int $iterations): int
{
    $matches = 0;
    for ($index = 0; $index < $iterations; ++$index) {
        if ($plan->has($capability)) {
            ++$matches;
        }
    }
    return $matches;
}

function oneLoop(AttributePlan $plan, string $capability, int $iterations): int
{
    $matches = 0;
    for ($index = 0; $index < $iterations; ++$index) {
        if ($plan->one($capability) !== null) {
            ++$matches;
        }
    }
    return $matches;
}

function exceptionLoop(AttributePlan $plan, string $capability, int $iterations): int
{
    $exceptions = 0;
    for ($index = 0; $index < $iterations; ++$index) {
        try {
            $plan->one($capability);
        } catch (AttributeCompositionException) {
            ++$exceptions;
        }
    }
    return $exceptions;
}

$normalIterations = (int) ($options['iterations'] ?? 100000);
$exceptionIterations = (int) ($options['exception-iterations'] ?? 500);
if ($normalIterations < 1 || $exceptionIterations < 1) {
    throw new RuntimeException('Iteration counts must be positive.');
}
$cases = [
    ['has/empty', hasLoop(...), $empty, FamilyCapability::class, false, false],
    ['has/single', hasLoop(...), $single, FamilyCapability::class, true, false],
    ['has/single-miss', hasLoop(...), $single, MissingCapability::class, false, false],
    ['has/interleaved-first', hasLoop(...), $interleaved, FirstCapability::class, true, false],
    ['has/multiple', hasLoop(...), $interleaved, FamilyCapability::class, true, false],
    ['has/interleaved-miss', hasLoop(...), $interleaved, MissingCapability::class, false, false],
    ['one/empty', oneLoop(...), $empty, FamilyCapability::class, false, false],
    ['one/single', oneLoop(...), $single, FamilyCapability::class, true, false],
    ['one/single-miss', oneLoop(...), $single, MissingCapability::class, false, false],
    ['one/interleaved-last', oneLoop(...), $interleaved, LastCapability::class, true, false],
    ['one/interleaved-miss', oneLoop(...), $interleaved, MissingCapability::class, false, false],
    ['one/multiple-error', exceptionLoop(...), $interleaved, FamilyCapability::class, true, true],
];
$samples = [];
foreach ($cases as [$name, $loop, $plan, $capability, $present, $exceptionCase]) {
    $iterations = $exceptionCase ? $exceptionIterations : $normalIterations;
    $loop($plan, $capability, $exceptionCase ? 20 : 2000);
    gc_collect_cycles();
    gc_disable();
    $start = hrtime(true);
    $checksum = $loop($plan, $capability, $iterations);
    $elapsed = hrtime(true) - $start;
    gc_enable();
    if ($checksum !== ($present ? $iterations : 0)) {
        throw new RuntimeException('Unexpected measured result: ' . $name);
    }
    $samples[$name] = [
        'iterations' => $iterations,
        'elapsed_ns' => $elapsed,
        'ns_per_call' => $elapsed / $iterations,
        'checksum' => $checksum,
        'exception_case' => $exceptionCase,
    ];
}
if (NativeArgument::$constructions !== 0) {
    throw new RuntimeException('Metadata lookup evaluated an attribute argument.');
}
$origin = (new ReflectionClass(AttributePlan::class))->getFileName();
if (realpath($origin) !== realpath($selectedFile)
    || hash_file('sha256', $origin) !== $sourceHashes['Attribute/Composition/AttributePlan.php']
) {
    throw new RuntimeException('Measured class source mismatch.');
}
echo json_encode([
    'php' => PHP_VERSION,
    'binary' => PHP_BINARY,
    'source' => $selectedSource,
    'attribute_plan_sha256' => hash_file('sha256', $origin),
    'source_files_hashed_at_startup' => count($sourceHashes),
    'source_fingerprint_at_startup' => $sourceFingerprint,
    'source_fingerprint_algorithm' => 'SHA-256 of sorted UTF-8 src/path + NUL + raw file bytes + NUL.',
    'loaded_ini' => php_ini_loaded_file(),
    'opcache_enabled_cli' => ini_get('opcache.enable_cli'),
    'samples' => $samples,
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

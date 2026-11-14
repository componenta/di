<?php

declare(strict_types=1);

use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Resolver\TypeHints;

// Usage: php -n -d opcache.enable_cli=0 ParameterTargetHelpers.php <source-tree> <vendor/autoload.php> [iterations]
// Startup, loading and warmup are excluded. Run baseline/fix in serial ABBA
// rounds under the same PHP binary and vendor dependencies before comparing.
if ($argc < 3 || $argc > 4) {
    fwrite(STDERR, "Usage: php ParameterTargetHelpers.php <source-tree> <vendor/autoload.php> [iterations]\n");
    exit(64);
}
$source = realpath($argv[1]);
$vendor = realpath($argv[2]);
$iterations = filter_var($argv[3] ?? 500000, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($source === false || !is_dir($source . '/src') || $vendor === false || !is_file($vendor) || $iterations === false) {
    throw new RuntimeException('Supply an existing source tree, vendor autoloader and a positive iteration count.');
}
$loader = require $vendor;
if (!$loader instanceof Composer\Autoload\ClassLoader) {
    throw new RuntimeException('The vendor autoloader must return a Composer ClassLoader.');
}
$loader->setPsr4('Componenta\\DI\\', $source . '/src');
$loader->addClassMap([
    ParameterTarget::class => $source . '/src/Resolver/Target/ParameterTarget.php',
    TypeHints::class => $source . '/src/Resolver/TypeHints.php',
]);

#[Attribute(Attribute::TARGET_PARAMETER)]
class BenchLabel
{
    public function __construct(public readonly string $value) {}
}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class BenchChildLabel extends BenchLabel {}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class BenchMetadata {}

function exactTarget(#[BenchLabel('value')] string $value): void {}
function inheritedTarget(#[BenchChildLabel('value')] string $value): void {}
function missingTarget(#[BenchMetadata] string $value): void {}

function hasLoop(ParameterTarget $target, int $iterations): int
{
    $checksum = 0;
    for ($i = 0; $i < $iterations; ++$i) {
        $checksum += (int) $target->hasAttribute(BenchLabel::class);
    }
    return $checksum;
}

function firstLoop(ParameterTarget $target, int $iterations): int
{
    $checksum = 0;
    for ($i = 0; $i < $iterations; ++$i) {
        $checksum += (int) ($target->firstAttribute(BenchLabel::class) instanceof BenchLabel);
    }
    return $checksum;
}

$targets = [
    'exact' => new ParameterTarget(new ReflectionParameter('exactTarget', 0)),
    'inherited' => new ParameterTarget(new ReflectionParameter('inheritedTarget', 0)),
    'miss' => new ParameterTarget(new ReflectionParameter('missingTarget', 0)),
];
$origins = [];
foreach ([ParameterTarget::class, TypeHints::class] as $class) {
    $actual = new ReflectionClass($class)->getFileName();
    $relative = $class === ParameterTarget::class ? '/src/Resolver/Target/ParameterTarget.php' : '/src/Resolver/TypeHints.php';
    if ($actual !== realpath($source . $relative)) {
        throw new RuntimeException('Wrong source origin for ' . $class . ': ' . $actual);
    }
    $origins[$class] = ['file' => $actual, 'sha256' => hash_file('sha256', $actual)];
}

$autoloads = 0;
$observer = static function (string $class) use (&$autoloads): void {
    ++$autoloads;
};
spl_autoload_register($observer);
$samples = [];
foreach (['has', 'first'] as $method) {
    foreach ($targets as $kind => $target) {
        $loop = $method === 'has' ? hasLoop(...) : firstLoop(...);
        $warmup = $loop($target, 20000);
        $expectedWarmup = $kind === 'miss' ? 0 : 20000;
        if ($warmup !== $expectedWarmup) {
            throw new RuntimeException('Warmup checksum failed.');
        }
        $start = hrtime(true);
        $checksum = $loop($target, $iterations);
        $elapsed = hrtime(true) - $start;
        if ($checksum !== ($kind === 'miss' ? 0 : $iterations)) {
            throw new RuntimeException('Timed checksum failed.');
        }
        $samples[$method . '_' . $kind] = ['iterations' => $iterations, 'elapsed_ns' => $elapsed, 'ns_per_operation' => $elapsed / $iterations, 'checksum' => $checksum];
    }
}
spl_autoload_unregister($observer);
if ($autoloads !== 0) {
    throw new RuntimeException('Loaded-case benchmark unexpectedly invoked an autoloader.');
}
echo json_encode([
    'php' => PHP_VERSION,
    'php_binary' => PHP_BINARY,
    'vendor_autoload' => realpath($vendor),
    'opcache_enable_cli' => ini_get('opcache.enable_cli'),
    'jit' => ini_get('opcache.jit'),
    'origins' => $origins,
    'autoload_callbacks' => $autoloads,
    'samples' => $samples,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";

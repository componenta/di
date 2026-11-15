<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\BenchmarkStatistics;

/** @param list<int> $samples */
test('workload reports use the median for single even and odd sample counts', function (int $rounds, array $samples, int $median): void {
    // Control the timer in the report's namespace; execute its complete public
    // entry point so timing noise cannot hide an incorrect summary statistic.
    $code = <<<'PHP'
namespace DiPerformanceReassessment;

function hrtime(bool $asNumber = false): int
{
    static $position = 0;
    $ticks = [1000, 1100, 2000, 2300, 3000, 3200];
    if (!$asNumber || !isset($ticks[$position])) {
        throw new \RuntimeException('The benchmark requested an unexpected clock reading.');
    }
    return $ticks[$position++];
}

$root = $argv[1];
putenv('COMPONENTA_DI_BENCH_AUTOLOAD=' . $root . '/vendor/autoload.php');
putenv('COMPONENTA_DI_BENCH_SOURCE=' . $root . '/src');
putenv('DI_REASSESS_ITERATIONS=1');
putenv('DI_REASSESS_COLD_ITERATIONS=1');
putenv('DI_REASSESS_COUNT_ITERATIONS=1');
putenv('DI_REASSESS_ROUNDS=' . $argv[2]);
putenv('DI_REASSESS_FILTER=default-cache/clean/warm/make-plain');
require $root . '/docs/audit/reproductions/WorkloadNecessity.php';
PHP;

    $process = proc_open([
        PHP_BINARY, '-n', '-r', $code, dirname(__DIR__, 2), (string) $rounds,
    ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new \RuntimeException('Could not start the workload report.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    expect(proc_close($process))->toBe(0, $stdout . $stderr)
        ->and($stderr)->toBe('');
    /** @var array{cases: array<string, array{latency_rounds_ns_per_operation: list<int>, median_ns_per_operation: int}>} $report */
    $report = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
    expect($report['cases'])->toHaveCount(1);
    $case = $report['cases']['default-cache/clean/warm/make-plain'];
    expect($case['latency_rounds_ns_per_operation'])->toBe($samples)
        ->and($case['median_ns_per_operation'])->toBe($median);
})->with([
    'single sample' => [1, [100], 100],
    'even sample count' => [2, [100, 300], 200],
    'odd sample count' => [3, [100, 300, 200], 200],
]);

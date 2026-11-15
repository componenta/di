<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\AuditSourceSelection;

use RuntimeException;

test('availability observations use the selected source through the public CLI', function (bool $explicitPaths): void {
    $root = dirname(__DIR__, 2);
    $source = realpath($root . '/src');
    $autoload = realpath($root . '/vendor/autoload.php');
    if ($source === false || $autoload === false) {
        throw new RuntimeException('The audit fixture requires source files and a generated autoloader.');
    }

    $environment = getenv();
    unset($environment['COMPONENTA_DI_AUDIT_AUTOLOAD'], $environment['COMPONENTA_DI_AUDIT_SOURCE']);
    if ($explicitPaths) {
        $environment['COMPONENTA_DI_AUDIT_AUTOLOAD'] = $autoload;
        $environment['COMPONENTA_DI_AUDIT_SOURCE'] = $source;
    }

    $process = proc_open([
        PHP_BINARY,
        '-n',
        $root . '/docs/audit/reproductions/AttributeAvailabilityObservations.php',
        'between-resolutions',
    ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the availability observation script.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    expect(proc_close($process))->toBe(0, $stdout . $stderr);
    $report = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($report) || !is_string($report['source'] ?? null)) {
        throw new RuntimeException('The observation report must name the selected source.');
    }

    expect($report['scenario'] ?? null)->toBe('between-resolutions')
        ->and($report['preloaded'] ?? null)->toBeFalse()
        ->and($report['observations'] ?? null)->toBe([
            ['value' => 0, 'exception' => null],
            ['value' => 1, 'exception' => null],
        ])
        ->and(realpath($report['source']))->toBe($source)
        ->and($report['checked_declarations'] ?? null)->toBeInt()->toBeGreaterThan(0)
        ->and($report['constructors'] ?? null)->toBe(2)
        ->and($report['handlers'] ?? null)->toBe(1);
})->with(['default repository paths' => false, 'explicit matching paths' => true]);

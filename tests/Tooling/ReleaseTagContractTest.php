<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Tooling\ReleaseTagContract;

use PHPUnit\Framework\Assert;
use Symfony\Component\Process\Process;

test('release tags use the same validation and prerelease classification', function (string $subject, ?string $expected): void {
    if (!is_executable('/bin/bash')) {
        Assert::markTestSkipped('The release workflow runs in Bash on Linux.');
    }

    $process = new Process(['/bin/bash', '.github/scripts/release-tag.sh', $subject], dirname(__DIR__, 2));
    $process->run();

    if ($expected === null) {
        expect($process->getExitCode())->toBe(1)
            ->and($process->getOutput())->toBe('')
            ->and($process->getErrorOutput())->toContain('Invalid release subject');
    } else {
        expect($process->getExitCode())->toBe(0)
            ->and(rtrim($process->getOutput()))->toBe($expected);
    }
})->with([
    'stable with body' => ["Release v5.0.7\n\nNotes", 'v5.0.7 false'],
    'hyphen RC' => ['Release v5.0.7-rc.1', 'v5.0.7-rc.1 true'],
    'dotted RC' => ['Release v5.0.7.RC1', 'v5.0.7.RC1 true'],
    'development tag' => ['Release v5.0.7-dev', 'v5.0.7-dev true'],
    'unknown suffix' => ['Release v5.0.7.preview', null],
    'unknown hyphen suffix' => ['Release v5.0.7-preview', null],
    'wrong major' => ['Release v6.0.7', null],
    'leading zero' => ['Release v5.0.07', null],
    'missing release prefix' => ['v5.0.7', null],
]);

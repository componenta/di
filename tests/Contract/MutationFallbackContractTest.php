<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\DI\Tests\Support\MutationFallback;
use Pest\Mutate\Event\Events\Test\Outcome\Uncovered;
use Pest\Mutate\Mutation;
use Pest\Mutate\MutationTest;
use Pest\Mutate\Support\MutationTestResult;
use Pest\Mutate\Support\StreamWrapper;
use Symfony\Component\Finder\SplFileInfo;

test('uncovered mutations receive the outcome of a real fallback process', function (
    int $replacementValue,
    MutationTestResult $expected,
): void {
    $mutationActive = getenv('PEST_MUTATION_TESTING') !== false;
    if ($mutationActive) {
        StreamWrapper::disable();
    }
    $source = tempnam(sys_get_temp_dir(), 'mutation-original-');
    $replacement = tempnam(sys_get_temp_dir(), 'mutation-replacement-');
    if ($source === false || $replacement === false) {
        throw new \RuntimeException('Cannot create mutation fixtures.');
    }

    try {
        file_put_contents($source, '<?php return 42;');
        file_put_contents($replacement, '<?php return ' . $replacementValue . ';');
        $test = new MutationTest(new Mutation(
            new SplFileInfo($source, '', ''),
            'fallback-contract',
            'fixture',
            1,
            1,
            '',
            $replacement,
        ));
        $test->updateResult(MutationTestResult::Uncovered);
        $script = '$original = getenv("PEST_MUTATION_TESTING");'
            . 'if ($original !== $argv[1]) { throw new RuntimeException("Wrong original file"); }'
            . 'exit((require getenv("PEST_MUTATION_FILE")) === 42 ? 0 : 1);';
        $fallback = new MutationFallback([PHP_BINARY, '-r', $script, $source], __DIR__);

        $fallback->notify(new Uncovered($test));

        expect($test->result())->toBe($expected);
    } finally {
        unlink($source);
        unlink($replacement);
        if ($mutationActive) {
            StreamWrapper::enable();
        }
    }
})->with([
    'behavior changed' => [41, MutationTestResult::Tested],
    'behavior preserved' => [42, MutationTestResult::Untested],
]);

test('a failing unmodified worker prevents mutation scores from being accepted', function (): void {
    $active = getenv('PEST_MUTATION_TESTING') !== false;
    if ($active) {
        StreamWrapper::disable();
    }
    try {
        $fallback = new MutationFallback(
            [PHP_BINARY, '-r', 'fwrite(STDERR, "CONTROL_FAILURE"); exit(1);'],
            __DIR__,
        );

        expect(fn() => $fallback->verifyControl())->toThrow(\RuntimeException::class, 'CONTROL_FAILURE');
    } finally {
        if ($active) {
            StreamWrapper::enable();
        }
    }
});

test('parallel fallback drains every worker before waiting for another worker to finish', function (
    string $stream,
    int $replacementValue,
    MutationTestResult $expected,
): void {
    $active = getenv('PEST_MUTATION_TESTING') !== false;
    if ($active) {
        StreamWrapper::disable();
    }
    $directory = sys_get_temp_dir() . '/mutation-batch-' . bin2hex(random_bytes(8));
    if (!mkdir($directory)) {
        throw new \RuntimeException('Cannot create mutation batch fixtures.');
    }
    $source = $directory . '/original.php';
    $waiting = $directory . '/waiting.php';
    $writing = $directory . '/writing.php';
    $reports = $directory . '/reports';

    try {
        file_put_contents($source, '<?php return 42;');
        file_put_contents($waiting, '<?php while (!file_exists(__DIR__ . "/ready")) { usleep(1000); } return 42;');
        file_put_contents($writing, '<?php fwrite(' . $stream . ', str_repeat("x", 2 * 1024 * 1024));'
            . 'file_put_contents(__DIR__ . "/ready", "ready"); return ' . $replacementValue . ';');
        $waitingTest = new MutationTest(new Mutation(new SplFileInfo($source, '', ''), 'waiting', 'fixture', 1, 1, '', $waiting));
        $writingTest = new MutationTest(new Mutation(new SplFileInfo($source, '', ''), 'writing', 'fixture', 1, 1, '', $writing));
        // Each worker has its own marker and appends again if the fallback reruns it.
        $command = [PHP_BINARY, '-n', '-r',
            '$mutation = getenv("PEST_MUTATION_FILE");'
            . 'if ($mutation === false || file_put_contents($mutation . ".runs", ".", FILE_APPEND) !== 1) {'
            . 'throw new RuntimeException("Cannot record mutation worker start");}'
            . 'exit((require $mutation) === 42 ? 0 : 1);',
        ];
        $fallback = new MutationFallback($command, $directory, timeout: 5.0, reportDirectory: $reports);

        $fallback->prime([$waitingTest, $writingTest], 2);
        foreach ([$writingTest, $waitingTest, $writingTest] as $test) {
            $fallback->notify(new Uncovered($test));
        }

        expect($waitingTest->result())->toBe(MutationTestResult::Untested)
            ->and($writingTest->result())->toBe($expected)
            ->and(file_get_contents($waiting . '.runs'))->toBe('.')
            ->and(file_get_contents($writing . '.runs'))->toBe('.')
            ->and(file_get_contents($reports . '/waiting.log'))->toBe('exit=0' . PHP_EOL)
            ->and(file_get_contents($reports . '/writing.log'))->toBe(
                'exit=' . ($replacementValue === 42 ? 0 : 1) . PHP_EOL . str_repeat('x', 2 * 1024 * 1024),
            );
    } finally {
        foreach (glob($reports . '/*') ?: [] as $path) {
            unlink($path);
        }
        if (is_dir($reports)) {
            rmdir($reports);
        }
        foreach (glob($directory . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($directory);
        if ($active) {
            StreamWrapper::enable();
        }
    }
})->with(['stdout' => 'STDOUT', 'stderr' => 'STDERR'])->with([
    'behavior preserved' => [42, MutationTestResult::Untested],
    'behavior changed' => [41, MutationTestResult::Tested],
]);

test('parallel fallback still reports a worker that exceeds its own timeout', function (): void {
    $active = getenv('PEST_MUTATION_TESTING') !== false;
    if ($active) {
        StreamWrapper::disable();
    }
    $source = tempnam(sys_get_temp_dir(), 'mutation-timeout-');
    if ($source === false) {
        throw new \RuntimeException('Cannot create the timeout fixture.');
    }

    try {
        file_put_contents($source, '<?php while (true) { usleep(1000); }');
        $test = new MutationTest(new Mutation(new SplFileInfo($source, '', ''), 'timeout', 'fixture', 1, 1, '', $source));
        $fallback = new MutationFallback([PHP_BINARY, '-n', '-r', 'require getenv("PEST_MUTATION_FILE");'], __DIR__, timeout: 0.05);

        $fallback->prime([$test], 1);
        $fallback->notify(new Uncovered($test));

        expect($test->result())->toBe(MutationTestResult::Timeout);
    } finally {
        unlink($source);
        if ($active) {
            StreamWrapper::enable();
        }
    }
});

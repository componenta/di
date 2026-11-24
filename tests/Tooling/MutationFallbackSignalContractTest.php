<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Tooling\MutationFallbackSignalContract;

use Componenta\DI\Tests\Support\MutationFallback;
use Pest\Mutate\Event\Events\Test\Outcome\Uncovered;
use Pest\Mutate\Mutation;
use Pest\Mutate\MutationTest;
use Pest\Mutate\Support\MutationTestResult;
use Pest\Mutate\Support\StreamWrapper;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Process\Process;

test('signaled fallback workers leave diagnostics and abort the mutation run', function (string $phase): void {
    $mutationActive = getenv('PEST_MUTATION_TESTING') !== false;
    if ($mutationActive) {
        StreamWrapper::disable();
    }
    $directory = sys_get_temp_dir() . '/mutation-signal-' . bin2hex(random_bytes(8));
    $filesystem = new Filesystem();
    try {
        $filesystem->mkdir($directory . '/src');
        $source = $directory . '/src/original.php';
        $replacement = $directory . '/replacement.php';
        $waiting = $directory . '/waiting.php';
        $reports = $directory . '/reports';
        file_put_contents($source, '<?php return 42;');
        file_put_contents($replacement, '<?php return 41;');
        file_put_contents($waiting, '<?php return 40;');
        $file = new SplFileInfo($source, '', 'original.php');
        $test = new MutationTest(new Mutation($file, 'signaled', 'fixture', 12, 12, '', $replacement));
        $test->updateResult(MutationTestResult::Uncovered);
        $sibling = new MutationTest(new Mutation($file, 'waiting', 'fixture', 12, 12, '', $waiting));

        $script = <<<'SH'
            if [ "$1" != control ] && [ "$PEST_MUTATION_FILE" = "$2" ]; then exit 0; fi
            if [ "$PEST_MUTATION_FILE" = "$3" ]; then
                printf '%s' "$$" > sibling.pid
                exec /bin/sleep 30
            fi
            if [ "$1" = parallel ]; then
                while [ ! -s sibling.pid ]; do /bin/sleep 0.01; done
            fi
            printf 'stdout before signal\n'
            printf 'stderr before signal\n' >&2
            kill -15 "$$"
            SH;
        $fallback = new MutationFallback(
            ['/bin/sh', '-c', $script, 'worker', $phase, $source, $waiting],
            $directory,
            timeout: 5.0,
            reportDirectory: $reports,
            controlSource: $source,
        );
        if ($phase !== 'control') {
            $fallback->verifyControl();
        }
        $run = match ($phase) {
            'control' => $fallback->verifyControl(...),
            'parallel' => fn() => $fallback->prime([$test, $sibling], 2),
            default => fn() => $fallback->notify(new Uncovered($test)),
        };
        $diagnostic = $phase === 'control'
            ? 'Unmodified mutation worker terminated by signal 15.'
            : 'Mutation worker signaled (src/original.php:12) terminated by signal 15.';

        expect($run)->toThrow(RuntimeException::class, $diagnostic);

        $worker = $phase === 'control' ? 'control' : 'signaled';
        expect(file_get_contents($reports . '/' . $worker . '.log'))
            ->toBe('exit=143' . PHP_EOL . $diagnostic . PHP_EOL . "stdout before signal\nstderr before signal\n")
            ->and($test->result())->toBe(MutationTestResult::Uncovered);

        if ($phase === 'parallel') {
            $pid = file_get_contents($directory . '/sibling.pid');
            if ($pid === false) {
                throw new RuntimeException('The sibling worker did not start.');
            }
            $probe = new Process(['/bin/sh', '-c', 'kill -0 "$1"', 'probe', $pid], $directory, timeout: 5.0);
            $probe->run();
            expect($probe->isSuccessful())->toBeFalse()
                ->and($sibling->result())->toBe(MutationTestResult::None);
        }
    } finally {
        $filesystem->remove($directory);
        if ($mutationActive) {
            StreamWrapper::enable();
        }
    }
})->with(['control', 'parallel', 'direct'])->skip(PHP_OS_FAMILY === 'Windows', 'Process signals require a POSIX worker.');

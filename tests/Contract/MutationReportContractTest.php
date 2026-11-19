<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\DI\Tests\Support\MutationFallback;
use Componenta\DI\Tests\Support\MutationReport;
use Pest\Mutate\Event\Events\Test\Outcome\Uncovered;
use Pest\Mutate\Event\Events\TestSuite\FinishMutationSuite;
use Pest\Mutate\Mutation;
use Pest\Mutate\MutationSuite;
use Pest\Mutate\MutationTest;
use Pest\Mutate\Support\StreamWrapper;
use RuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\SplFileInfo;

test('mutation reports retain real worker outcomes and distinguish incomplete runs without changing scores', function (): void {
    $active = getenv('PEST_MUTATION_TESTING') !== false;
    if ($active) {
        StreamWrapper::disable();
    }
    $directory = sys_get_temp_dir() . '/di-mutation-report-' . bin2hex(random_bytes(8));
    mkdir($directory);
    try {
        $source = $directory . '/source.php';
        $original = '<?php return 42;';
        file_put_contents($source, $original);
        $suite = new MutationSuite();
        $replacements = [
            'tested' => '<?php return 41;',
            'untested' => '<?php return 42;',
            'timeout' => '<?php usleep(2_000_000); return 42;',
            'pending' => '<?php return 42;',
        ];
        $plainDiff = '-return "<tag>";' . PHP_EOL . '+return "</tag>";';
        foreach ($replacements as $id => $replacement) {
            $path = $directory . '/' . $id . '.php';
            file_put_contents($path, $replacement);
            $suite->repository->add(new Mutation(
                new SplFileInfo($source, '', ''),
                $id,
                'report-fixture',
                1,
                1,
                '<fg=red>' . OutputFormatter::escape($plainDiff) . '</>',
                $path,
            ));
        }
        $tests = [];
        foreach ($suite->repository->all() as $collection) {
            foreach ($collection->tests() as $test) {
                $tests[$test->getId()] = $test;
            }
        }
        $command = [PHP_BINARY, '-r', 'exit((require getenv("PEST_MUTATION_FILE")) === 42 ? 0 : 1);'];
        foreach ($tests as $id => $test) {
            if ($id === 'pending') {
                continue;
            }
            $fallback = new MutationFallback(
                $command,
                $directory,
                timeout: $id === 'timeout' ? 0.1 : 5.0,
                reportDirectory: $directory . '/workers',
            );
            $fallback->notify(new Uncovered($test));
        }
        $before = array_map(static fn(MutationTest $test): string => $test->result()->value, $tests);
        $score = $suite->score();
        $path = $directory . '/report.json';
        $reporter = new MutationReport($directory, $path, ['tests/mutate.php', '--mutate', '--path=source.php']);
        $reporter->notify(new FinishMutationSuite($suite));
        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException('Expected a saved mutation report.');
        }
        $report = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($report)) {
            throw new RuntimeException('Expected a mutation report object.');
        }

        expect($before)->toBe(['tested' => 'tested', 'untested' => 'untested', 'timeout' => 'timeout', 'pending' => 'none'])
            ->and(array_map(static fn(MutationTest $test): string => $test->result()->value, $tests))->toBe($before)
            ->and($suite->score())->toBe($score)
            ->and($report['schema_version'])->toBe(1)
            ->and($report['complete'])->toBeFalse()
            ->and($report['counts'])->toBe(['total' => 4, 'tested' => 1, 'untested' => 1, 'timeout' => 1, 'uncovered' => 0, 'none' => 1])
            ->and($report['files'])->toBe(['source.php' => ['sha256' => hash('sha256', $original)]])
            ->and($report['arguments'])->toBe(['tests/mutate.php', '--mutate', '--path=source.php'])
            ->and(is_file($directory . '/workers/timeout.log'))->toBeTrue();
        $expected = [];
        foreach ($before as $id => $result) {
            $expected[] = [
                'id' => $id,
                'file' => 'source.php',
                'line' => 1,
                'end_line' => 1,
                'mutator' => 'report-fixture',
                'result' => $result,
                'diff' => $plainDiff,
                'duration_seconds' => null,
                'duration_unavailable_reason' => $id === 'pending' ? 'not_run' : 'not_recorded_by_pest',
            ];
        }
        expect($report['outcomes'])->toBe($expected);

        $fallback = new MutationFallback($command, $directory, timeout: 5.0);
        $fallback->notify(new Uncovered($tests['pending']));
        $reporter->notify(new FinishMutationSuite($suite));
        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException('Expected the updated mutation report.');
        }
        $report = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($report)) {
            throw new RuntimeException('Expected the updated mutation report object.');
        }
        expect($report['complete'])->toBeTrue()
            ->and($report['counts'])->toBe(['total' => 4, 'tested' => 1, 'untested' => 2, 'timeout' => 1, 'uncovered' => 0, 'none' => 0]);
    } finally {
        new Filesystem()->remove($directory);
        if ($active) {
            StreamWrapper::enable();
        }
    }
});

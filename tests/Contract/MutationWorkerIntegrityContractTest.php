<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\DI\Tests\Support\MutationArtifactCache;
use Componenta\DI\Tests\Support\MutationReport;
use Componenta\DI\Tests\Support\MutationWorkerCheck;
use Pest\Mutate\Event\Events\TestSuite\FinishMutationSuite;
use Pest\Mutate\Mutation;
use Pest\Mutate\MutationSuite;
use Pest\Mutate\Repositories\ConfigurationRepository;
use Pest\Mutate\Repositories\TelemetryRepository;
use Pest\Mutate\Support\MutationTestResult;
use Pest\Mutate\Support\StreamWrapper;
use Pest\Support\Container;
use PHPUnit\Framework\Assert;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Process\Process;

test('mutation generation rebuilds stale artifacts while retaining prior scores', function (): void {
    $active = getenv('PEST_MUTATION_TESTING') !== false;
    if ($active) {
        StreamWrapper::disable();
    }
    $directory = sys_get_temp_dir() . '/di-mutation-artifacts-' . bin2hex(random_bytes(8));
    mkdir($directory);
    try {
        $contents = '<?php return 42;';
        $replacement = $directory . '/' . hash('xxh3', $contents);
        file_put_contents($replacement, $contents);
        $file = new SplFileInfo(__FILE__, '', basename(__FILE__));
        $cache = new MutationArtifactCache($directory . '/cache');
        $cache->set('previous-run-result', ['mutation' => 'tested']);
        $cache->set('generated-mutation', [new Mutation($file, 'mutation', 'fixture', 1, 1, '', $replacement)]);
        expect($cache->has('generated-mutation'))->toBeTrue();

        unlink($replacement);
        expect($cache->has('generated-mutation'))->toBeFalse()
            ->and($cache->get('generated-mutation'))->toBeNull()
            ->and($cache->get('previous-run-result'))->toBe(['mutation' => 'tested']);

        file_put_contents($replacement, '<?php return 41;');
        $cache->set('generated-mutation', [new Mutation($file, 'mutation', 'fixture', 1, 1, '', $replacement)]);
        expect($cache->has('generated-mutation'))->toBeFalse();
    } finally {
        new Filesystem()->remove($directory);
        if ($active) {
            StreamWrapper::enable();
        }
    }
});

test('the mutation gate requires an installed substitution and a valid worker outcome', function (
    bool $validOutcome,
    bool $emptySuite,
    string $diagnostic,
): void {
    $active = getenv('PEST_MUTATION_TESTING') !== false;
    $key = MutationWorkerCheck::ENVIRONMENT_KEY;
    $parentDirectory = getenv($key);
    if ($active) {
        StreamWrapper::disable();
    }
    $directory = sys_get_temp_dir() . '/di-mutation-startup-' . bin2hex(random_bytes(8));
    mkdir($directory);
    try {
        $root = dirname(__DIR__, 2);
        $source = realpath($root . '/src/Internal/functions.php');
        if ($source === false) {
            throw new RuntimeException('Cannot locate the Composer function file.');
        }
        $replacement = $directory . '/replacement.php';
        if ($emptySuite) {
            $replacement = $source;
        } elseif ($validOutcome) {
            file_put_contents($replacement, '<?php throw new RuntimeException("WORKER_REACHED_SUBSTITUTION");');
        }
        $mutation = new Mutation(new SplFileInfo($source, '', ''), 'startup', 'fixture', 1, 1, '', $replacement);
        $suite = new MutationSuite();
        $suite->repository->add($mutation);
        foreach ($suite->repository->all() as $collection) {
            foreach ($collection->tests() as $test) {
                $test->updateResult(MutationTestResult::Tested);
            }
        }

        $workers = new MutationWorkerCheck($directory . '/workers [current]');
        $workers->start();
        $command = [PHP_BINARY, '-d', 'opcache.enable_cli=0', $root . '/tests/mutate.php', 'tests/Contract/TypedEntryContractTest.php', '--no-coverage', '--colors=never'];
        if ($emptySuite) {
            $command[] = '--filter=NoSuchDiMutationWorkerTest';
        }
        $process = new Process($command, $root, [
            'PEST_MUTATION_TESTING' => $source,
            'PEST_MUTATION_FILE' => $replacement,
        ]);
        $process->run();
        expect($process->getExitCode())->toBeGreaterThan(0)
            ->and($process->getOutput() . $process->getErrorOutput())->toContain($diagnostic);

        $path = $directory . '/report.json';
        $reporter = new MutationReport($root, $path, workers: $workers);
        $event = new FinishMutationSuite($suite);
        if ($validOutcome) {
            $reporter->notify($event);
        } else {
            expect(fn() => $reporter->notify($event))->toThrow(RuntimeException::class, 'worker startup');
        }
        $report = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($report)) {
            throw new RuntimeException('Expected a mutation report object.');
        }
        expect($report['complete'])->toBe($validOutcome)
            ->and($report['pest_score'])->toBe($validOutcome ? 100 : null)
            ->and($report['worker_failures'] ?? [])->toBe($validOutcome ? [] : ['startup']);
    } finally {
        if ($parentDirectory === false) {
            putenv($key);
            unset($_SERVER[$key], $_ENV[$key]);
        } else {
            putenv($key . '=' . $parentDirectory);
            $_SERVER[$key] = $_ENV[$key] = $parentDirectory;
        }
        new Filesystem()->remove($directory);
        if ($active) {
            StreamWrapper::enable();
        }
    }
})->with([
    'error after substitution' => [true, false, 'WORKER_REACHED_SUBSTITUTION'],
    'missing override' => [false, false, 'Override file does not exist'],
    'empty selection' => [false, true, 'No tests found'],
]);

test('covered mutations require a valid worker termination', function (string $scenario, ?int $score, string $outcome): void {
    if (str_starts_with($scenario, 'signal') && (!function_exists('posix_kill') || !defined('SIGKILL'))) {
        Assert::markTestSkipped('Process signals require a POSIX worker.');
    }

    $active = getenv('PEST_MUTATION_TESTING') !== false;
    if ($active) {
        StreamWrapper::disable();
    }
    $key = MutationWorkerCheck::ENVIRONMENT_KEY;
    $parentDirectory = getenv($key);
    $container = Container::getInstance();
    $parentTelemetry = $container->get(TelemetryRepository::class);
    $telemetry = new TelemetryRepository();
    $telemetry->initialTestSuiteDuration(0.0);
    $container->add(TelemetryRepository::class, $telemetry);
    $directory = sys_get_temp_dir() . '/di-mutation-primary-' . bin2hex(random_bytes(8));
    $filesystem = new Filesystem();
    $filesystem->mkdir($directory);
    try {
        $root = dirname(__DIR__, 2);
        $source = $directory . '/source.php';
        file_put_contents($source, '<?php return 42;');
        $source = realpath($source);
        if ($source === false) {
            throw new RuntimeException('Cannot locate the covered mutation fixture.');
        }
        $replacement = $directory . '/replacement.php';
        file_put_contents($replacement, $scenario === 'assertion' ? '<?php return 41;' : '<?php return 42;');
        $mutation = new Mutation(new SplFileInfo($source, '', ''), 'primary', 'fixture', 1, 1, '', $replacement);
        $suite = new MutationSuite();
        $suite->repository->add($mutation);
        $test = array_values($suite->repository->all())[0]->tests()[0];

        $workers = new MutationWorkerCheck($directory . '/workers');
        $workers->start();
        $worker = <<<'PHP'
            require $argv[1];
            $scenario = $argv[2];
            if ($scenario === 'no-shutdown-record') {
                register_shutdown_function(static fn() => exit(1));
            }
            $source = getenv('PEST_MUTATION_TESTING');
            \Pest\Mutate\Support\StreamWrapper::start($source, getenv('PEST_MUTATION_FILE'));
            \Componenta\DI\Tests\Support\MutationWorkerCheck::acknowledgeStartup();
            $value = require $source;
            \Pest\Mutate\Support\StreamWrapper::disable();
            match ($scenario) {
                'signal' => posix_kill(getmypid(), SIGKILL),
                'signal-on-shutdown' => register_shutdown_function(static fn() => posix_kill(getmypid(), SIGKILL)),
                'timeout' => sleep(10),
                default => null,
            };
            \PHPUnit\Framework\Assert::assertSame(42, $value);
            PHP;
        $configuration = new ConfigurationRepository();
        $configuration->cliConfiguration->fromArguments(['--path=' . $source]);
        expect($test->start(
            [$source => [1 => ['P\\Tests\\Fixture::__pest_evaluable_sample']]],
            $configuration->mergedConfiguration(),
            [PHP_BINARY, '-r', $worker, '--', $root . '/tests/bootstrap.php', $scenario],
        ))->toBeTrue();
        while (!$test->hasFinished()) {
            usleep(1000);
        }
        expect(glob($directory . '/workers/*'))->toHaveCount(1);

        $path = $directory . '/report.json';
        $reporter = new MutationReport($root, $path, workers: $workers);
        $event = new FinishMutationSuite($suite);
        if ($score === null) {
            expect(fn() => $reporter->notify($event))->toThrow(RuntimeException::class, 'worker startup');
        } else {
            $reporter->notify($event);
        }
        $report = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($report)) {
            throw new RuntimeException('Expected a mutation report object.');
        }
        expect($report['complete'])->toBe($score !== null)
            ->and($report['pest_score'])->toBe($score)
            ->and($report)->toHaveKey('outcomes.0.result', $outcome)
            ->and($report['worker_failures'] ?? [])->toBe($score === null ? ['primary'] : []);
    } finally {
        $container->add(TelemetryRepository::class, $parentTelemetry);
        if ($parentDirectory === false) {
            putenv($key);
            unset($_SERVER[$key], $_ENV[$key]);
        } else {
            putenv($key . '=' . $parentDirectory);
            $_SERVER[$key] = $_ENV[$key] = $parentDirectory;
        }
        $filesystem->remove($directory);
        if ($active) {
            StreamWrapper::enable();
        }
    }
})->with([
    'unchanged fixture' => ['pass', 0, 'untested'],
    'assertion failure' => ['assertion', 100, 'tested'],
    'shutdown without confirmation' => ['no-shutdown-record', null, 'worker_failure'],
    'signal during fixture' => ['signal', null, 'worker_failure'],
    'signal during shutdown' => ['signal-on-shutdown', null, 'worker_failure'],
    'timeout' => ['timeout', 100, 'timeout'],
]);

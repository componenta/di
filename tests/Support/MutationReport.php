<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Support;

use Composer\InstalledVersions;
use Pest\Mutate\Event\Events\TestSuite\FinishMutationSuite;
use Pest\Mutate\Event\Events\TestSuite\FinishMutationSuiteSubscriber;
use Pest\Mutate\Support\MutationTestResult;
use RuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

/** Saves final outcomes without changing Pest's results or score. */
final readonly class MutationReport implements FinishMutationSuiteSubscriber
{
    /** @param list<string> $arguments */
    public function __construct(
        private string $directory,
        private string $path,
        private array $arguments = [],
        private ?MutationWorkerCheck $workers = null,
    ) {}

    public function notify(FinishMutationSuite $event): void
    {
        $root = realpath($this->directory) ?: $this->directory;
        $formatter = new OutputFormatter(false);
        $files = [];
        $outcomes = [];
        $workerFailures = [];
        foreach ($event->mutationSuite->repository->all() as $collection) {
            foreach ($collection->tests() as $test) {
                $mutation = $test->mutation;
                $source = $mutation->file->getRealPath() ?: $mutation->file->getPathname();
                $file = Path::makeRelative($source, $root);
                $files[$file] ??= ['sha256' => hash_file('sha256', $source) ?: null];
                $duration = $test->duration();
                $result = $test->result();
                $workerFailed = $this->workers !== null
                    && $result !== MutationTestResult::None
                    && $result !== MutationTestResult::Uncovered
                    && !$this->workers->consumeOutcome($test);
                if ($workerFailed) {
                    $workerFailures[] = $test->getId();
                }
                $outcomes[] = [
                    'id' => $test->getId(),
                    'file' => $file,
                    'line' => $mutation->startLine,
                    'end_line' => $mutation->endLine,
                    'mutator' => $mutation->mutator,
                    'result' => $workerFailed ? 'worker_failure' : $result->value,
                    'diff' => $formatter->format($mutation->diff),
                    'duration_seconds' => $duration > 0 ? $duration : null,
                    'duration_unavailable_reason' => $duration > 0 ? null : match ($result) {
                        MutationTestResult::None => 'not_run',
                        MutationTestResult::Uncovered => 'uncovered',
                        default => 'not_recorded_by_pest',
                    },
                ];
            }
        }

        $tools = [];
        foreach (['pestphp/pest', 'pestphp/pest-plugin-mutate', 'phpunit/phpunit', 'nikic/php-parser'] as $package) {
            $tools[$package] = InstalledVersions::isInstalled($package) ? InstalledVersions::getPrettyVersion($package) : null;
        }
        $repository = $event->mutationSuite->repository;
        $report = [
            'schema_version' => 1,
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'complete' => $repository->notRun() === 0 && $workerFailures === [],
            'head' => $this->head(),
            'root' => Path::canonicalize($root),
            'php' => ['version' => PHP_VERSION, 'binary' => PHP_BINARY, 'os' => PHP_OS_FAMILY],
            'tools' => $tools,
            'arguments' => $this->arguments,
            'files' => $files,
            'counts' => [
                'total' => $repository->total(),
                'tested' => $repository->tested(),
                'untested' => $repository->untested(),
                'timeout' => $repository->timedOut(),
                'uncovered' => $repository->uncovered(),
                'none' => $repository->notRun(),
            ],
            'pest_score' => $workerFailures === [] ? $event->mutationSuite->score() : null,
            'outcomes' => $outcomes,
        ];
        if ($workerFailures !== []) {
            $report['worker_failures'] = $workerFailures;
        }

        new Filesystem()->dumpFile(
            $this->path,
            json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        );
        if ($workerFailures !== []) {
            throw new RuntimeException(sprintf('Mutation worker startup or termination failed for %d replacements. See %s.', count($workerFailures), $this->path));
        }
    }

    private function head(): ?string
    {
        try {
            $process = new Process(['git', 'rev-parse', 'HEAD'], $this->directory, timeout: 5.0);
            $process->run();
            if ($process->isSuccessful()) {
                return trim($process->getOutput());
            }
        } catch (ExceptionInterface) {
        }

        $head = getenv('GITHUB_SHA');
        return $head !== false && preg_match('/\A[0-9a-f]{40,64}\z/i', $head) === 1 ? $head : null;
    }
}

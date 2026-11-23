<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Support;

use Pest\Mutate\MutationTest;
use Pest\Mutate\Plugins\Mutate;
use Pest\Mutate\Support\MutationTestResult;
use PHPUnit\TestRunner\TestResult\Facade as TestResultFacade;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/** Verifies installed replacements and the termination of mutation workers. */
final class MutationWorkerCheck
{
    public const string ENVIRONMENT_KEY = 'COMPONENTA_DI_MUTATION_WORKERS';

    private const string SHUTDOWN_SUFFIX = '.shutdown';

    private static ?string $startupRecord = null;

    /** @var array<string,int>|null */
    private ?array $ready = null;

    private readonly ReflectionProperty $primaryProcess;

    public function __construct(private readonly string $directory)
    {
        // Pest's result cannot distinguish a test failure from a signal to its worker.
        // Fail at startup if this Pest adapter can no longer inspect that worker.
        $this->primaryProcess = new ReflectionProperty(MutationTest::class, 'process');
    }

    public function start(): void
    {
        $filesystem = new Filesystem();
        $filesystem->mkdir($this->directory, 0700);
        if (!putenv(self::ENVIRONMENT_KEY . '=' . $this->directory)) {
            throw new RuntimeException('Cannot configure mutation worker verification.');
        }
        // Symfony Process uses the superglobals when inheriting the environment.
        $_SERVER[self::ENVIRONMENT_KEY] = $_ENV[self::ENVIRONMENT_KEY] = $this->directory;
        register_shutdown_function(fn() => $filesystem->remove($this->directory));
    }

    public static function acknowledgeStartup(): void
    {
        $directory = getenv(self::ENVIRONMENT_KEY);
        $source = getenv(Mutate::ENV_MUTATION_TESTING);
        if ($directory === false || $source === false) {
            return;
        }
        // A native fixture spawned by a worker is part of that worker's test.
        putenv(self::ENVIRONMENT_KEY);
        unset($_SERVER[self::ENVIRONMENT_KEY], $_ENV[self::ENVIRONMENT_KEY]);
        $override = getenv(Mutate::ENV_MUTATION_FILE);
        $hash = hash_file('xxh3', $source);
        if ($override === false || $hash === false || $hash !== hash_file('xxh3', $override)) {
            throw new RuntimeException('Mutation worker startup did not install the requested replacement.');
        }

        $record = $directory . '/' . self::key($source, $override) . '.' . bin2hex(random_bytes(8));
        if (file_put_contents($record, '') === false) {
            throw new RuntimeException('Cannot record mutation worker startup.');
        }
        self::$startupRecord = $record;
        register_shutdown_function(static function () use ($record): void {
            // Bootstrap and test failures can still reach PHP shutdown.
            if (self::$startupRecord === $record && !rename($record, $record . self::SHUTDOWN_SUFFIX)) {
                throw new RuntimeException('Cannot record mutation worker shutdown.');
            }
        });
    }

    public static function discardEmptyRun(): void
    {
        if (self::$startupRecord === null || TestResultFacade::result()->numberOfTestsRun() !== 0) {
            return;
        }
        if (!unlink(self::$startupRecord)) {
            throw new RuntimeException('Cannot discard a mutation startup record without tests.');
        }
        self::$startupRecord = null;
    }

    public function consumeOutcome(MutationTest $test): bool
    {
        $timedOut = $test->result() === MutationTestResult::Timeout;
        if (!$timedOut && $this->wasSignaled($test)) {
            return false;
        }
        $mutation = $test->mutation;
        $source = $mutation->file->getRealPath();
        if ($source === false) {
            return false;
        }
        $this->ready ??= array_count_values(array_map(
            static fn(string $name): string => explode('.', $name, 2)[0]
                . (str_ends_with($name, self::SHUTDOWN_SUFFIX) ? self::SHUTDOWN_SUFFIX : ''),
            scandir($this->directory, SCANDIR_SORT_NONE) ?: [],
        ));
        $key = self::key($source, $mutation->modifiedSourcePath);
        // A timeout can use a startup record so a completed run can use its shutdown record.
        if (!$timedOut || ($this->ready[$key] ?? 0) === 0) {
            $key .= self::SHUTDOWN_SUFFIX;
        }
        $remaining = $this->ready[$key] ?? 0;
        if ($remaining === 0) {
            return false;
        }
        $this->ready[$key] = $remaining - 1;
        return true;
    }

    private function wasSignaled(MutationTest $test): bool
    {
        // Fallback runs manage their own process and reject unexpected signals there.
        if (!$this->primaryProcess->isInitialized($test)) {
            return false;
        }
        $process = $this->primaryProcess->getValue($test);
        if (!$process instanceof Process) {
            throw new RuntimeException('Cannot inspect the primary mutation worker with this version of Pest.');
        }
        return $process->hasBeenSignaled();
    }

    private static function key(string $source, string $override): string
    {
        return hash('sha256', $source . "\0" . $override);
    }
}

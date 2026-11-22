<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Support;

use Pest\Mutate\Mutation;
use Pest\Mutate\Plugins\Mutate;
use PHPUnit\TestRunner\TestResult\Facade as TestResultFacade;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

/** Records which workers reached PHPUnit with the requested replacement installed. */
final class MutationWorkerCheck
{
    public const string ENVIRONMENT_KEY = 'COMPONENTA_DI_MUTATION_WORKERS';

    private static ?string $startupRecord = null;

    /** @var array<string,int>|null */
    private ?array $ready = null;

    public function __construct(private readonly string $directory) {}

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

    public function consumeStartup(Mutation $mutation): bool
    {
        $this->ready ??= array_count_values(array_map(
            static fn(string $name): string => explode('.', $name, 2)[0],
            scandir($this->directory, SCANDIR_SORT_NONE) ?: [],
        ));
        $source = $mutation->file->getRealPath();
        if ($source === false) {
            return false;
        }
        $key = self::key($source, $mutation->modifiedSourcePath);
        if (($this->ready[$key] ?? 0) === 0) {
            return false;
        }
        --$this->ready[$key];
        return true;
    }

    private static function key(string $source, string $override): string
    {
        return hash('sha256', $source . "\0" . $override);
    }
}

<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

final class MutationRuntime
{
    /** @var array<string, bool> */
    private static array $mbstringAvailability = [];

    /**
     * @param list<string> $arguments
     * @return list<string>
     */
    public static function workerArguments(array $arguments): array
    {
        // Pest supplies a filter from the initial suite's coverage.
        return array_values(array_filter(
            $arguments,
            static fn(string $argument, int $index): bool => $argument !== '--filter'
                && !str_starts_with($argument, '--filter=')
                && ($arguments[$index - 1] ?? null) !== '--filter',
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    /** @return list<string> */
    public static function command(): array
    {
        $command = [PHP_BINARY];
        $ini = php_ini_loaded_file();
        if ($ini === false) {
            $command[] = '-n';
        } else {
            array_push($command, '-c', $ini);
        }
        array_push($command, '-d', 'extension_dir=' . ini_get('extension_dir'));
        array_push($command, '-d', 'opcache.enable_cli=0');
        if (extension_loaded('mbstring') && !self::loadsMbstring($command)) {
            array_push($command, '-d', 'extension=mbstring');
        }
        array_push($command, '-d', 'memory_limit=' . ini_get('memory_limit'));

        return $command;
    }

    /** @param list<string> $command */
    private static function loadsMbstring(array $command): bool
    {
        $configuration = serialize([$command, getenv('PHP_INI_SCAN_DIR')]);
        if (isset(self::$mbstringAvailability[$configuration])) {
            return self::$mbstringAvailability[$configuration];
        }
        // A parent CLI extension flag is not inherited, even with a selected ini.
        $probe = new Process([
            ...$command, '-r', 'echo extension_loaded("mbstring") ? "1" : "0";',
        ], timeout: 5.0);
        $probe->mustRun();

        return self::$mbstringAvailability[$configuration] = match ($probe->getOutput()) {
            '1' => true,
            '0' => false,
            default => throw new RuntimeException('Cannot determine whether mbstring is available to the mutation worker.'),
        };
    }
}

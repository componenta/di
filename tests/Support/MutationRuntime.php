<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

final class MutationRuntime
{
    private static ?bool $builtInMbstring = null;

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
        if ($ini === false && extension_loaded('mbstring') && !self::hasBuiltInMbstring()) {
            array_push($command, '-d', 'extension=mbstring');
        }
        array_push($command, '-d', 'opcache.enable_cli=0');

        return $command;
    }

    private static function hasBuiltInMbstring(): bool
    {
        if (self::$builtInMbstring !== null) {
            return self::$builtInMbstring;
        }
        // With no ini, a loaded extension may be built in or supplied with -d.
        $probe = new Process([
            PHP_BINARY, '-n', '-r', 'echo extension_loaded("mbstring") ? "1" : "0";',
        ], timeout: 5.0);
        $probe->mustRun();

        return self::$builtInMbstring = match ($probe->getOutput()) {
            '1' => true,
            '0' => false,
            default => throw new RuntimeException('Cannot determine whether mbstring is built into the PHP runtime.'),
        };
    }
}

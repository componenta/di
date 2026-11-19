<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Pest\Mutate\Support\StreamWrapper;
use Symfony\Component\Process\Process;

test('mutation workers inherit the selected PHP configuration', function (): void {
    $active = getenv('PEST_MUTATION_TESTING') !== false;
    if ($active) {
        StreamWrapper::disable();
    }
    $ini = tempnam(sys_get_temp_dir(), 'mutation-ini-');
    if ($ini === false) {
        throw new \RuntimeException('Cannot create PHP configuration fixture.');
    }
    try {
        file_put_contents($ini, "precision=9\nopcache.enable_cli=0\n");
        $root = dirname(__DIR__, 2);
        $vendor = is_file($root . '/vendor/autoload.php') ? $root . '/vendor' : dirname($root) . '/var-export/vendor';
        $script = 'require ' . var_export($vendor . '/autoload.php', true) . ';'
            . 'require ' . var_export($root . '/tests/Support/MutationRuntime.php', true) . ';'
            . '$command = Componenta\\DI\\Tests\\Support\\MutationRuntime::command();'
            . '$process = new Symfony\\Component\\Process\\Process([...$command, "-r", \'echo ini_get("precision");\']);'
            . '$process->run(); echo $process->getOutput(); exit($process->getExitCode());';
        $process = new Process([PHP_BINARY, '-c', $ini, '-r', $script], $root, [
            'PEST_MUTATION_TESTING' => false,
            'PEST_MUTATION_FILE' => false,
            'XDEBUG_MODE' => 'off',
        ]);
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and(trim($process->getOutput()))->toBe('9');
    } finally {
        unlink($ini);
        if ($active) {
            StreamWrapper::enable();
        }
    }
});

test('mutation workers preserve mbstring without startup diagnostics', function (bool $withIni, bool $extensionInIni): void {
    $active = getenv('PEST_MUTATION_TESTING') !== false;
    if ($active) {
        StreamWrapper::disable();
    }
    $ini = null;
    try {
        $bareRuntime = new Process([
            PHP_BINARY, '-n', '-r', 'exit(extension_loaded("mbstring") ? 0 : 1);',
        ], timeout: 5.0);
        $bareRuntime->run();
        expect($bareRuntime->getExitCode())->toBeIn([0, 1]);

        $command = [PHP_BINARY];
        if ($withIni) {
            $created = tempnam(sys_get_temp_dir(), 'mutation-extension-ini-');
            if ($created === false) {
                throw new \RuntimeException('Cannot create PHP configuration fixture.');
            }
            $ini = $created;
            file_put_contents($ini, "precision=9\nopcache.enable_cli=0\n"
                . ($extensionInIni && $bareRuntime->getExitCode() === 1 ? "extension=mbstring\n" : ''));
            array_push($command, '-c', $ini);
        } else {
            $command[] = '-n';
        }
        array_push($command, '-d', 'extension_dir=' . ini_get('extension_dir'));
        if ($bareRuntime->getExitCode() === 1 && !$extensionInIni) {
            array_push($command, '-d', 'extension=mbstring');
        }
        $root = dirname(__DIR__, 2);
        $vendor = is_file($root . '/vendor/autoload.php') ? $root . '/vendor' : dirname($root) . '/var-export/vendor';
        $worker = 'echo json_encode([php_ini_loaded_file(), extension_loaded("mbstring"),'
            . 'mb_strtoupper("été", "UTF-8"), filter_var(ini_get("opcache.enable_cli"), FILTER_VALIDATE_BOOL)], JSON_THROW_ON_ERROR);';
        $script = 'require ' . var_export($vendor . '/autoload.php', true) . ';'
            . 'require ' . var_export($root . '/tests/Support/MutationRuntime.php', true) . ';'
            . '$command = Componenta\\DI\\Tests\\Support\\MutationRuntime::command();'
            . '$process = new Symfony\\Component\\Process\\Process([...$command, "-r", ' . var_export($worker, true) . ']);'
            . '$process->run(); echo $process->getOutput(); fwrite(STDERR, $process->getErrorOutput()); exit($process->getExitCode());';
        $process = new Process([...$command, '-r', $script], $root, [
            'PEST_MUTATION_TESTING' => false,
            'PEST_MUTATION_FILE' => false,
            'XDEBUG_MODE' => 'off',
            'PHP_INI_SCAN_DIR' => '',
        ], timeout: 10.0);
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and($process->getErrorOutput())->toBe('')
            ->and($process->getOutput())->toBe(json_encode([
                $ini === null ? false : realpath($ini), true, 'ÉTÉ', false,
            ], JSON_THROW_ON_ERROR));
    } finally {
        if ($ini !== null) {
            unlink($ini);
        }
        if ($active) {
            StreamWrapper::enable();
        }
    }
})->with([
    'without an ini' => [false, false],
    'with an ini and CLI extension' => [true, false],
    'with an ini extension' => [true, true],
]);

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

test('mutation workers without an ini preserve mbstring without startup diagnostics', function (): void {
    $active = getenv('PEST_MUTATION_TESTING') !== false;
    if ($active) {
        StreamWrapper::disable();
    }
    try {
        $bareRuntime = new Process([
            PHP_BINARY, '-n', '-r', 'exit(extension_loaded("mbstring") ? 0 : 1);',
        ], timeout: 5.0);
        $bareRuntime->run();
        expect($bareRuntime->getExitCode())->toBeIn([0, 1]);

        $command = [PHP_BINARY, '-n', '-d', 'extension_dir=' . ini_get('extension_dir')];
        if ($bareRuntime->getExitCode() === 1) {
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
        ], timeout: 10.0);
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and($process->getErrorOutput())->toBe('')
            ->and($process->getOutput())->toBe(json_encode([false, true, 'ÉTÉ', false], JSON_THROW_ON_ERROR));
    } finally {
        if ($active) {
            StreamWrapper::enable();
        }
    }
});

<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Pest\Mutate\Support\StreamWrapper;
use RuntimeException;
use Symfony\Component\Process\Process;

test('mutation bootstrap applies function substitutions before Composer can load them', function (): void {
    $mutationActive = getenv('PEST_MUTATION_TESTING') !== false;
    if ($mutationActive) {
        // Native file locks are needed while Symfony Process manages its child pipes on Windows.
        StreamWrapper::disable();
    }
    try {
        $packageDirectory = dirname(__DIR__, 2);
        $functions = realpath($packageDirectory . '/src/Internal/functions.php');
        if ($functions === false) {
            throw new RuntimeException('Cannot locate DI functions.');
        }
        $replacement = tempnam(sys_get_temp_dir(), 'di-mutation-');
        if ($replacement === false) {
            throw new RuntimeException('Cannot create mutation bootstrap fixture.');
        }

        try {
            file_put_contents($replacement, "<?php throw new RuntimeException('DI_MUTATION_BOOTSTRAP_SENTINEL');\n");
            $command = [
                PHP_BINARY,
                '-d',
                'opcache.enable_cli=0',
                '-d',
                'xdebug.mode=off',
                $packageDirectory . '/tests/mutate.php',
                'tests/V5/RequestMappingContractTest.php',
                '--filter=MapRequest excludes fields from the mapped result',
                '--colors=never',
                '--compact',
                '--do-not-cache-result',
            ];
            $original = new Process($command, $packageDirectory, [
                'PEST_MUTATION_TESTING' => $functions,
                'PEST_MUTATION_FILE' => $functions,
            ]);
            $original->run();
            expect($original->getExitCode())->toBe(0)
                ->and($original->getOutput())->toContain('1 passed');

            $mutated = new Process($command, $packageDirectory, [
                'PEST_MUTATION_TESTING' => $functions,
                'PEST_MUTATION_FILE' => $replacement,
            ]);
            $mutated->run();
            expect($mutated->getExitCode())->toBeGreaterThan(0, $mutated->getOutput() . $mutated->getErrorOutput())
                ->and($mutated->getOutput() . $mutated->getErrorOutput())->toContain('DI_MUTATION_BOOTSTRAP_SENTINEL');
        } finally {
            unlink($replacement);
        }
    } finally {
        if ($mutationActive) {
            StreamWrapper::enable();
        }
    }
});

test('native cold fixtures apply inherited mutation substitutions without warming collaborators', function (string $target, string $fixture, ?string $scenario): void {
    $mutationActive = getenv('PEST_MUTATION_TESTING') !== false;
    if ($mutationActive) {
        // Symfony Process needs native file locks while managing child pipes on Windows.
        StreamWrapper::disable();
    }
    try {
        $packageDirectory = dirname(__DIR__, 2);
        $original = realpath($packageDirectory . '/src/' . $target);
        if ($original === false) {
            throw new RuntimeException('Cannot locate the native child mutation target.');
        }
        $replacement = tempnam(sys_get_temp_dir(), 'di-child-mutation-');
        if ($replacement === false) {
            throw new RuntimeException('Cannot create the native child mutation fixture.');
        }

        try {
            file_put_contents($replacement, "<?php throw new RuntimeException('DI_NATIVE_CHILD_MUTATION_SENTINEL');\n");
            $command = [
                PHP_BINARY,
                '-n',
                $packageDirectory . '/tests/Fixtures/' . $fixture,
                $packageDirectory . '/vendor/autoload.php',
                $packageDirectory . '/src',
            ];
            if ($scenario !== null) {
                $command[] = $scenario;
            }

            $ordinary = new Process($command, $packageDirectory, [
                'PEST_MUTATION_TESTING' => false,
                'PEST_MUTATION_FILE' => false,
            ]);
            $ordinary->run();
            expect($ordinary->getExitCode())->toBe(0, $ordinary->getOutput() . $ordinary->getErrorOutput());

            $control = new Process($command, $packageDirectory, [
                'PEST_MUTATION_TESTING' => $original,
                'PEST_MUTATION_FILE' => $original,
            ]);
            $control->run();
            expect($control->getExitCode())->toBe(0, $control->getOutput() . $control->getErrorOutput())
                ->and($control->getOutput())->toBe($ordinary->getOutput())
                ->and($control->getErrorOutput())->toBe('');

            $mutated = new Process($command, $packageDirectory, [
                'PEST_MUTATION_TESTING' => $original,
                'PEST_MUTATION_FILE' => $replacement,
            ]);
            $mutated->run();
            expect($mutated->getExitCode())->toBeGreaterThan(0, $mutated->getOutput() . $mutated->getErrorOutput())
                ->and($mutated->getOutput() . $mutated->getErrorOutput())->toContain('DI_NATIVE_CHILD_MUTATION_SENTINEL');
        } finally {
            unlink($replacement);
        }
    } finally {
        if ($mutationActive) {
            StreamWrapper::enable();
        }
    }
})->with(['class' => 'Container.php', 'Composer function file' => 'Internal/functions.php'])
    ->with([
        'bootstrap collaborator' => ['ContainerBootstrapCold.php', null],
        'runtime mutation collaborator' => ['ContainerMutationCold.php', 'alias'],
        'construction retry collaborator' => ['ContainerConstructionRetry.php', 'cache'],
    ]);

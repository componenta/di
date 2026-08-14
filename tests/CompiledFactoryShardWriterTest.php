<?php

declare(strict_types=1);

use Componenta\DI\Compile\Factory\CompiledFactoryShardWriter;

it('rejects an existing shard whose contents differ from the immutable artifact', function (): void {
    $file = sys_get_temp_dir() . '/componenta-corrupt-shard-' . bin2hex(random_bytes(6)) . '.php';
    file_put_contents($file, "<?php\nreturn 'corrupt';\n");

    try {
        expect(fn() => (new CompiledFactoryShardWriter())->write(
            $file,
            "<?php\nreturn 'expected';\n",
        ))->toThrow(
            RuntimeException::class,
            'already exists with unexpected contents',
        );
    } finally {
        @unlink($file);
    }
});

it('reuses an existing shard when its contents match exactly', function (): void {
    $file = sys_get_temp_dir() . '/componenta-valid-shard-' . bin2hex(random_bytes(6)) . '.php';
    $code = "<?php\nreturn 'expected';\n";
    file_put_contents($file, $code);

    try {
        (new CompiledFactoryShardWriter())->write($file, $code);

        expect(file_get_contents($file))->toBe($code);
    } finally {
        @unlink($file);
    }
});

it('syntax-checks an existing matching shard before reusing it', function (): void {
    $file = sys_get_temp_dir() . '/componenta-invalid-shard-' . bin2hex(random_bytes(6)) . '.php';
    $code = "<?php\nfunction broken( {\n";
    file_put_contents($file, $code);

    try {
        expect(fn() => (new CompiledFactoryShardWriter())->write($file, $code))
            ->toThrow(
                RuntimeException::class,
                'failed PHP compile validation',
            );
    } finally {
        @unlink($file);
    }
});

it('normalizes directory creation warnings into the shard writer exception boundary', function (): void {
    $root = sys_get_temp_dir() . '/componenta-shard-directory-' . bin2hex(random_bytes(6));
    $blocker = $root . '/blocker';
    $file = $blocker . '/generated.php';
    $warnings = [];
    mkdir($root);
    file_put_contents($blocker, 'not-a-directory');

    set_error_handler(
        static function (int $_severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        },
        E_WARNING,
    );

    try {
        expect(fn() => (new CompiledFactoryShardWriter())->write(
            $file,
            "<?php\nreturn 'expected';\n",
        ))->toThrow(RuntimeException::class, 'Cannot create factory shard directory');

        expect($warnings)->toBe([]);
    } finally {
        restore_error_handler();
        @unlink($blocker);
        @rmdir($root);
    }
});

it('does not leak rename warnings when an atomic shard commit fails', function (): void {
    $root = sys_get_temp_dir() . '/componenta-shard-commit-' . bin2hex(random_bytes(6));
    $file = $root . '/generated.php';
    $warnings = [];
    $code = "<?php\nreturn 'expected';\n";
    mkdir($root);
    mkdir($file);

    set_error_handler(
        static function (int $_severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        },
        E_WARNING,
    );

    try {
        expect(fn() => (new CompiledFactoryShardWriter())->write($file, $code))
            ->toThrow(RuntimeException::class, 'Cannot activate generated factory shard');

        expect($warnings)->toBe([])
            ->and(is_dir($file))->toBeTrue();
    } finally {
        restore_error_handler();
        foreach (glob($root . '/generated.php.tmp.*') ?: [] as $temporary) {
            @unlink($temporary);
        }
        @rmdir($file);
        @rmdir($root);
    }
});

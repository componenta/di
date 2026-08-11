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

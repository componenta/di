<?php

declare(strict_types=1);

use Componenta\DI\Attribute\Proxy;
use Componenta\DI\ContainerBuilder;

final readonly class CompiledProxyAlternative {}

#[Proxy(CompiledProxyAlternative::class)]
final readonly class InvalidCompiledClassProxy {}

it('does not downgrade an invalid class-level proxy to the invokable path', function (): void {
    $directory = sys_get_temp_dir()
        . '/componenta-invalid-compiled-proxy-'
        . bin2hex(random_bytes(5));

    try {
        expect(fn () => (new ContainerBuilder())->compileFactories(
            entries: [InvalidCompiledClassProxy::class],
            directory: $directory,
        ))->toThrow(
            LogicException::class,
            'Class-level #[Proxy] must not specify a proxy class',
        );
    } finally {
        foreach (glob($directory . '/*') ?: [] as $file) {
            @unlink($file);
        }

        if (is_dir($directory)) {
            @rmdir($directory);
        }
    }
});

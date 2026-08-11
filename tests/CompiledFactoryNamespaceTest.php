<?php

declare(strict_types=1);

use Componenta\DI\ContainerBuilder;

final readonly class CompiledFactoryNamespaceTarget
{
    public function __construct(public string $value = 'default') {}
}

it('rejects invalid generated factory namespaces before writing source', function (): void {
    $directory = sys_get_temp_dir()
        . '/componenta-invalid-factory-namespace-'
        . bin2hex(random_bytes(5));

    try {
        expect(fn () => (new ContainerBuilder())->compileFactories(
            entries: [CompiledFactoryNamespaceTarget::class],
            directory: $directory,
            namespace: 'Componenta\\Generated; exit;',
        ))->toThrow(
            InvalidArgumentException::class,
            'is not a valid PHP namespace',
        );
    } finally {
        foreach (glob($directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
});

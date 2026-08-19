<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\DI\Attribute\NoConstructor;
use Componenta\DI\ContainerBuilder;
use InvalidArgumentException;

final class AuditTrivialEntry {}

final class AuditPrivateConstructorEntry
{
    private function __construct() {}
}

#[NoConstructor]
final class AuditNoConstructorEntry
{
    private function __construct()
    {
        throw new \RuntimeException('Constructor must not run.');
    }
}

test('has rejects a concrete class whose constructor cannot be called', function (): void {
    $container = (new ContainerBuilder())->build();

    expect($container->has(AuditPrivateConstructorEntry::class))->toBeFalse();
});

test('NoConstructor keeps inaccessible constructors resolvable', function (): void {
    $container = (new ContainerBuilder())->build();

    expect($container->has(AuditNoConstructorEntry::class))->toBeTrue()
        ->and($container->make(AuditNoConstructorEntry::class))->toBeInstanceOf(AuditNoConstructorEntry::class);
});

test('AOT rejects the same inaccessible constructor that runtime cannot resolve', function (): void {
    $directory = sys_get_temp_dir() . '/componenta-di-v5-ineligible-' . bin2hex(random_bytes(5));

    try {
        expect(fn() => (new ContainerBuilder())->compileFactories(
            [AuditPrivateConstructorEntry::class],
            $directory,
        ))->toThrow(InvalidArgumentException::class);
    } finally {
        foreach (glob($directory . '/container.factories.*.php') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
});

test('AOT emits a direct constructor only for a trivial prepared entry', function (): void {
    $suffix = bin2hex(random_bytes(5));
    $directory = sys_get_temp_dir() . '/componenta-di-v5-prepared-' . $suffix;
    $namespace = 'Componenta\\DI\\Tests\\Generated\\Prepared' . $suffix;

    try {
        $definitions = (new ContainerBuilder())->compileFactories(
            [AuditTrivialEntry::class, AuditNoConstructorEntry::class],
            $directory,
            namespace: $namespace,
        );

        $trivial = $definitions[AuditTrivialEntry::class];
        $noConstructor = $definitions[AuditNoConstructorEntry::class];
        $trivialCode = file_get_contents($directory . '/' . $trivial->file);
        $noConstructorCode = file_get_contents($directory . '/' . $noConstructor->file);

        expect($trivialCode)->toBeString()
            ->and($trivialCode)->toContain('return new \\' . AuditTrivialEntry::class . '();')
            ->and($noConstructorCode)->toBeString()
            ->and($noConstructorCode)->toContain('$this->objects->create(\\' . AuditNoConstructorEntry::class . '::class, $params)');
    } finally {
        foreach (glob($directory . '/container.factories.*.php') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
});

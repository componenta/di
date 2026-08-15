<?php

declare(strict_types=1);

use Componenta\DI\Compile\Parameter\PhpValueExporter;

enum ExportedCompilerCase
{
    case Ready;
}

it('exports only compiler-safe PHP literals', function (): void {
    expect(PhpValueExporter::export(null))->toBe('NULL')
        ->and(PhpValueExporter::export(true))->toBe('true')
        ->and(PhpValueExporter::export(42))->toBe('42')
        ->and(PhpValueExporter::export('value'))->toBe("'value'")
        ->and(PhpValueExporter::export(ExportedCompilerCase::Ready))
        ->toBe('\\' . ExportedCompilerCase::class . '::Ready')
        ->and(PhpValueExporter::export(['type' => ['one', 'two']]))
        ->toBe("['type' => [0 => 'one', 1 => 'two']]");
});

it('declines objects and closures so the compiler can use its runtime fallback', function (): void {
    expect(PhpValueExporter::export(new stdClass()))->toBeNull()
        ->and(PhpValueExporter::export(static fn(): int => 1))->toBeNull();
});

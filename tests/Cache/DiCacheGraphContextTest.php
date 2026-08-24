<?php

declare(strict_types=1);

use Componenta\DI\Cache\DiCacheGraphExporter;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Exception\ExportException;

it('uses semantic root depth zero independently from wrapper indentation', function (): void {
    $config = ExportConfig::pretty()->withIndent('    ')->withTrailingComma();
    $code = (new DiCacheGraphExporter($config))->export(['value' => ['nested' => 1]]);

    expect($code)->toContain("    return [\n        'value' => [")
        ->and($code)->toContain("\n    ];\n})()");
});

it('applies maxDepth to DI graph values with the same root-zero boundary', function (): void {
    $config = new ExportConfig(maxDepth: 2);
    $exporter = new DiCacheGraphExporter($config);

    expect(eval('return ' . $exporter->export(['a' => ['b' => 1]]) . ';'))
        ->toBe(['a' => ['b' => 1]])
        ->and(fn() => $exporter->export(['a' => ['b' => ['c' => 1]]]))
        ->toThrow(ExportException::class, 'Maximum nesting depth');
});

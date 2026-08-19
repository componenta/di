<?php

declare(strict_types=1);

it('keeps class-level internal types under the Internal namespace', function (): void {
    $src = dirname(__DIR__, 2) . '/src';
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src));
    $violations = [];

    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = file_get_contents($file->getPathname());
        if (!is_string($source)) {
            continue;
        }

        $internalType = preg_match(
            '/\/\*\*(?:(?!\*\/)[\s\S])*@internal(?:(?!\*\/)[\s\S])*\*\/\s*(?:(?:final|readonly|abstract)\s+)*(?:class|interface|trait|enum)\s+/m',
            $source,
        ) === 1;
        if (!$internalType) {
            continue;
        }

        preg_match('/namespace\s+([^;]+);/', $source, $namespace);
        $resolved = trim($namespace[1] ?? '');
        if (!str_starts_with($resolved, 'Componenta\\DI\\Internal')) {
            $violations[] = str_replace($src . '/', '', $file->getPathname()) . ': ' . $resolved;
        }
    }

    expect($violations)->toBe([]);
});

it('exposes internal implementations only from their Internal FQCNs', function (): void {
    $moved = [
        'AliasResolver' => 'Componenta\\DI\\Internal\\AliasResolver',
        'ContainerBootstrapState' => 'Componenta\\DI\\Internal\\ContainerBootstrapState',
        'CycleGuard' => 'Componenta\\DI\\Internal\\CycleGuard',
        'DelegatorRegistry' => 'Componenta\\DI\\Internal\\DelegatorRegistry',
        'EntryCache' => 'Componenta\\DI\\Internal\\EntryCache',
        'ExternalContainerRegistry' => 'Componenta\\DI\\Internal\\ExternalContainerRegistry',
        'ProtectedServiceIds' => 'Componenta\\DI\\Internal\\ProtectedServiceIds',
    ];

    foreach ($moved as $legacy => $internal) {
        expect(class_exists('Componenta\\DI\\' . $legacy))->toBeFalse()
            ->and(class_exists($internal))->toBeTrue();
    }

    expect(class_exists('Componenta\\DI\\Compile\\Factory\\CompiledFactoryPathResolver'))->toBeFalse()
        ->and(class_exists('Componenta\\DI\\Internal\\Compile\\Factory\\CompiledFactoryPathResolver'))->toBeTrue()
        ->and(class_exists('Componenta\\DI\\Resolver\\Entry\\FactorySpecificationValidator'))->toBeFalse()
        ->and(class_exists('Componenta\\DI\\Internal\\Resolver\\Entry\\FactorySpecificationValidator'))->toBeTrue()
        ->and(class_exists('Componenta\\DI\\Resolver\\Parameter\\Request\\MappedRequestContext'))->toBeFalse()
        ->and(class_exists('Componenta\\DI\\Internal\\Resolver\\Parameter\\Request\\MappedRequestContext'))->toBeTrue()
        ->and(class_exists('Componenta\\DI\\Resolver\\Parameter\\Request\\MappedRequestParameterSourceGuard'))->toBeFalse()
        ->and(class_exists('Componenta\\DI\\Internal\\Resolver\\Parameter\\Request\\MappedRequestParameterSourceGuard'))->toBeTrue();
});

it('uses a function instead of the stateless request mapper helper class', function (): void {
    expect(class_exists('Componenta\\DI\\Resolver\\Parameter\\Request\\RequestMapperPipeline'))->toBeFalse()
        ->and(function_exists('Componenta\\DI\\transform_request_mapper_data'))->toBeTrue();
});
